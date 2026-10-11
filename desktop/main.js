'use strict';

/*
 * RetailPOS desktop launcher.
 *
 * Lifecycle: single instance -> splash -> wait for PostgreSQL (service is
 * managed by Windows, never by this app) -> start PHP's built-in server on
 * 127.0.0.1 -> wait for /up -> open the app window. While open: run the
 * Laravel scheduler every minute. On exit: stop only our PHP processes.
 *
 * Paths (all overridable through environment variables for testing):
 *   RETAILPOS_PHP       php executable   (default <resources>/php/php.exe)
 *   RETAILPOS_PHP_INI   php.ini          (default <resources>/php/php.ini)
 *   RETAILPOS_APP_DIR   Laravel app      (default <resources>/laravel)
 *   RETAILPOS_DATA_DIR  data directory   (default %ProgramData%\RetailPOS)
 */

const { app, BrowserWindow, dialog, Menu, shell } = require('electron');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const http = require('node:http');

const isWindows = process.platform === 'win32';
const resources = app.isPackaged ? process.resourcesPath : path.join(__dirname, '..');

const PATHS = {
  php: process.env.RETAILPOS_PHP || path.join(resources, 'php', isWindows ? 'php.exe' : 'php'),
  phpIni: process.env.RETAILPOS_PHP_INI || path.join(resources, 'php', 'php.ini'),
  app: process.env.RETAILPOS_APP_DIR || path.join(resources, 'laravel'),
  data: process.env.RETAILPOS_DATA_DIR || path.join(process.env.ProgramData || process.env.PROGRAMDATA || 'C:\\ProgramData', 'RetailPOS'),
};

const PREFERRED_PORT = 8763;
const DB_WAIT_SECONDS = Number(process.env.RETAILPOS_DB_WAIT || 60);
const HEALTH_WAIT_SECONDS = 60;
const ALLOW_DEVTOOLS = !app.isPackaged || process.env.RETAILPOS_DEVTOOLS === '1';

let server = null; // PHP built-in server process
let baseUrl = null;
let mainWindow = null;
let splash = null;
let schedulerTimer = null;
const tasks = new Set(); // background artisan commands (scheduler, catch-up backup)
let quitting = false;

// ---------------------------------------------------------------- logging

function logFile() {
  const dir = path.join(PATHS.data, 'storage', 'logs');
  try { fs.mkdirSync(dir, { recursive: true }); } catch { /* ignore */ }
  return path.join(dir, 'desktop.log');
}

function log(message) {
  const line = `[${new Date().toISOString()}] ${message}\n`;
  try {
    const file = logFile();
    if (fs.existsSync(file) && fs.statSync(file).size > 5 * 1024 * 1024) fs.renameSync(file, file + '.1');
    fs.appendFileSync(file, line);
  } catch { /* logging must never crash the app */ }
}

// ------------------------------------------------------------- utilities

/** Minimal .env reader: only the keys the launcher needs. Values are never logged. */
function readEnv() {
  const file = path.join(PATHS.data, '.env');
  if (!fs.existsSync(file)) return null;
  const env = {};
  for (const raw of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = raw.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m) env[m[1]] = m[2].replace(/^"(.*)"$/, '$1').replace(/^'(.*)'$/, '$1');
  }
  return env;
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

function canConnect(host, port, timeout = 1500) {
  return new Promise((resolve) => {
    const socket = net.connect({ host, port, timeout });
    const done = (ok) => { socket.destroy(); resolve(ok); };
    socket.once('connect', () => done(true));
    socket.once('timeout', () => done(false));
    socket.once('error', () => done(false));
  });
}

function portIsFree(port) {
  return new Promise((resolve) => {
    const srv = net.createServer();
    srv.once('error', () => resolve(false));
    srv.once('listening', () => srv.close(() => resolve(true)));
    srv.listen(port, '127.0.0.1');
  });
}

async function choosePort() {
  for (let p = PREFERRED_PORT; p < PREFERRED_PORT + 20; p++) {
    if (await portIsFree(p)) return p;
  }
  throw new Error('NO_FREE_PORT');
}

function httpOk(url) {
  return new Promise((resolve) => {
    const req = http.get(url, { timeout: 3000 }, (res) => { res.resume(); resolve(res.statusCode === 200); });
    req.on('error', () => resolve(false));
    req.on('timeout', () => { req.destroy(); resolve(false); });
  });
}

function phpArgs(args) {
  return fs.existsSync(PATHS.phpIni) ? ['-c', PATHS.phpIni, ...args] : args;
}

function phpEnv() {
  return {
    ...process.env,
    RETAILPOS_DATA_DIR: PATHS.data,
    APP_URL: baseUrl || '',
    PUBLIC_DISK_SERVE: 'true',
  };
}

/** Run an artisan command in the background (hidden window), tracked so we can wait for it on exit. */
function artisan(args, label) {
  const child = spawn(PATHS.php, phpArgs([path.join(PATHS.app, 'artisan'), ...args, '--no-ansi', '--no-interaction']), {
    cwd: PATHS.app, env: phpEnv(), windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'],
  });
  tasks.add(child);
  let output = '';
  child.stdout.on('data', (d) => { output += d; });
  child.stderr.on('data', (d) => { output += d; });
  child.on('exit', (code) => {
    tasks.delete(child);
    if (code !== 0) log(`${label} exited with ${code}: ${output.slice(-500)}`);
  });
  child.on('error', (e) => { tasks.delete(child); log(`${label} failed to start: ${e.message}`); });
  return child;
}

// --------------------------------------------------------------- errors

function fail(title, detail) {
  log(`FATAL: ${title} | ${detail}`);
  if (splash && !splash.isDestroyed()) splash.close();
  dialog.showMessageBoxSync({
    type: 'error',
    title: 'RetailPOS',
    message: title,
    detail: `${detail}\n\nسجل الأخطاء: ${logFile()}`,
    buttons: ['إغلاق'],
  });
  shutdown().then(() => app.exit(1));
}

// -------------------------------------------------------------- startup

async function start() {
  log(`Starting. app=${PATHS.app} data=${PATHS.data} packaged=${app.isPackaged}`);

  splash = new BrowserWindow({
    width: 420, height: 260, frame: false, resizable: false, center: true, show: true,
    backgroundColor: '#0f172a', webPreferences: { sandbox: true, contextIsolation: true, nodeIntegration: false, devTools: false },
  });
  splash.loadFile(path.join(__dirname, 'splash.html'));

  if (!fs.existsSync(PATHS.php) && !process.env.RETAILPOS_PHP) {
    return fail('لم يتم العثور على مكوّنات النظام', 'ملفات تشغيل PHP غير موجودة. أعد تثبيت البرنامج.');
  }
  if (!fs.existsSync(path.join(PATHS.app, 'artisan'))) {
    return fail('لم يتم العثور على ملفات النظام', 'مجلد التطبيق غير مكتمل. أعد تثبيت البرنامج.');
  }

  const env = readEnv();
  if (!env) {
    return fail('لم يكتمل إعداد النظام', `ملف الإعدادات غير موجود في:\n${PATHS.data}\n\nأعد تشغيل برنامج التثبيت لإكمال الإعداد.`);
  }

  // 1) PostgreSQL must be reachable (it runs as a Windows service).
  const dbHost = env.DB_HOST || '127.0.0.1';
  const dbPort = Number(env.DB_PORT || 5433);
  let dbReady = false;
  for (let i = 0; i < DB_WAIT_SECONDS && !dbReady; i++) {
    dbReady = await canConnect(dbHost, dbPort);
    if (!dbReady) await sleep(1000);
  }
  if (!dbReady) {
    return fail('قاعدة البيانات لا تعمل',
      `تعذّر الاتصال بخدمة قاعدة البيانات (${dbHost}:${dbPort}) خلال ${DB_WAIT_SECONDS} ثانية.\n\n` +
      'تحقق من أن خدمة "RetailPOS-PostgreSQL" تعمل من: لوحة التحكم ← أدوات Windows ← الخدمات،\n' +
      'أو أعد تشغيل الكمبيوتر.');
  }

  // 2) PHP built-in server on loopback only.
  let port;
  try { port = await choosePort(); } catch {
    return fail('تعذّر تشغيل النظام', 'لا يوجد منفذ محلي متاح (8763-8782).');
  }
  baseUrl = `http://127.0.0.1:${port}`;
  const serverScript = path.join(PATHS.app, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php');

  const serverLog = fs.openSync(path.join(path.dirname(logFile()), 'php-server.log'), 'a');
  server = spawn(PATHS.php, phpArgs(['-S', `127.0.0.1:${port}`, serverScript]), {
    cwd: path.join(PATHS.app, 'public'), env: phpEnv(), windowsHide: true, stdio: ['ignore', serverLog, serverLog],
  });
  let serverExited = false;
  server.on('exit', (code) => {
    serverExited = true;
    log(`PHP server exited (${code})`);
    if (!quitting && mainWindow) {
      const choice = dialog.showMessageBoxSync(mainWindow, {
        type: 'error', title: 'RetailPOS', message: 'توقف خادم النظام بشكل غير متوقع.',
        detail: 'لم تُفقد البيانات المحفوظة. يمكنك إعادة تشغيل البرنامج.', buttons: ['إعادة التشغيل', 'إغلاق'],
      });
      if (choice === 0) { app.relaunch(); }
      app.quit();
    }
  });
  server.on('error', (e) => fail('تعذّر تشغيل خادم النظام', e.message));

  // 3) Health check.
  let healthy = false;
  for (let i = 0; i < HEALTH_WAIT_SECONDS * 2 && !healthy && !serverExited; i++) {
    healthy = await httpOk(`${baseUrl}/up`);
    if (!healthy) await sleep(500);
  }
  if (!healthy) {
    return fail('تعذّر تشغيل النظام', 'لم يستجب خادم النظام. راجع ملف php-server.log في مجلد السجلات.');
  }
  log(`Ready at ${baseUrl}`);

  // 4) App window.
  createWindow();

  // 5) Background jobs: catch-up daily backup, then the scheduler every minute.
  artisan(['backup:run', '--scheduled', '--if-due'], 'catch-up backup');
  schedulerTimer = setInterval(() => {
    if (tasks.size < 3) artisan(['schedule:run'], 'scheduler');
  }, 60 * 1000);
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1366, height: 820, show: false, title: 'RetailPOS', backgroundColor: '#ffffff',
    autoHideMenuBar: true,
    webPreferences: {
      sandbox: true, contextIsolation: true, nodeIntegration: false,
      devTools: ALLOW_DEVTOOLS, plugins: true /* built-in PDF viewer for statements */, spellcheck: false,
    },
  });

  const origin = new URL(baseUrl).origin;
  const sameOrigin = (url) => { try { return new URL(url).origin === origin; } catch { return false; } };

  // Only the local application may be shown; nothing else can be navigated to.
  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (!sameOrigin(url)) { event.preventDefault(); log(`Blocked navigation to ${url}`); }
  });
  // Receipts / labels / reports open in their own window (window.open) for printing.
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (sameOrigin(url)) {
      return { action: 'allow', overrideBrowserWindowOptions: {
        width: 900, height: 900, autoHideMenuBar: true,
        webPreferences: { sandbox: true, contextIsolation: true, nodeIntegration: false, devTools: ALLOW_DEVTOOLS, plugins: true },
      } };
    }
    if (/^https:\/\//.test(url)) shell.openExternal(url); // e.g. a help link: system browser, never inside the app
    return { action: 'deny' };
  });
  mainWindow.webContents.on('did-create-window', (child) => {
    child.webContents.on('will-navigate', (event, url) => { if (!sameOrigin(url)) event.preventDefault(); });
  });

  mainWindow.webContents.on('before-input-event', (event, input) => {
    if (input.type !== 'keyDown') return;
    if (input.key === 'F5') { mainWindow.webContents.reload(); event.preventDefault(); }
    if (input.control && input.key.toLowerCase() === 'p') { mainWindow.webContents.print(); event.preventDefault(); }
    if (!ALLOW_DEVTOOLS && (input.key === 'F12' || (input.control && input.shift && input.key.toLowerCase() === 'i'))) event.preventDefault();
  });

  mainWindow.webContents.on('did-finish-load', () => {
    log(`Loaded: ${mainWindow.webContents.getURL()} | title: ${mainWindow.webContents.getTitle()}`);
  });
  mainWindow.webContents.on('render-process-gone', (_e, details) => {
    log(`Renderer gone: ${details.reason}`);
    if (!quitting) mainWindow.webContents.reload();
  });

  mainWindow.once('ready-to-show', () => {
    if (splash && !splash.isDestroyed()) splash.close();
    mainWindow.maximize();
    mainWindow.show();
  });
  mainWindow.on('closed', () => { mainWindow = null; app.quit(); });

  mainWindow.loadURL(`${baseUrl}/admin`);
}

// ------------------------------------------------------------- shutdown

/** Stop our processes only. PostgreSQL is a Windows service and is left running. */
async function shutdown() {
  quitting = true;
  if (schedulerTimer) clearInterval(schedulerTimer);

  // Let a running backup/scheduler finish (up to 2 minutes) instead of cutting it off.
  for (let i = 0; i < 240 && tasks.size > 0; i++) await sleep(500);
  for (const child of tasks) killTree(child);

  if (server && server.exitCode === null) killTree(server);
  log('Stopped.');
}

function killTree(child) {
  if (!child || child.exitCode !== null) return;
  if (isWindows) {
    spawn('taskkill', ['/pid', String(child.pid), '/T', '/F'], { windowsHide: true });
  } else {
    child.kill('SIGTERM');
  }
}

// ------------------------------------------------------------ app events

if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (mainWindow) { if (mainWindow.isMinimized()) mainWindow.restore(); mainWindow.focus(); }
  });

  Menu.setApplicationMenu(null);
  app.whenReady().then(start).catch((e) => fail('خطأ غير متوقع', String(e && e.stack || e)));

  let cleaned = false;
  app.on('before-quit', (event) => {
    if (cleaned) return;
    event.preventDefault();
    shutdown().finally(() => { cleaned = true; app.quit(); });
  });
  app.on('window-all-closed', () => app.quit());
  process.on('SIGTERM', () => app.quit());
  process.on('SIGINT', () => app.quit());
}
