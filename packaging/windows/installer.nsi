; RetailPOS Windows installer (NSIS 3, Unicode).
; Build: makensis -INPUTCHARSET UTF8 -DSTAGE_DIR=<stage> -DAPP_VERSION=1.0.0 -DOUT_FILE=<exe> installer.nsi
;
; Program files : $PROGRAMFILES64\RetailPOS     (replaced on upgrade)
; Business data : $COMMONAPPDATA\RetailPOS      (never deleted by this installer)

Unicode true
!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "x64.nsh"
!include "WinVer.nsh"
!include "FileFunc.nsh"

!ifndef STAGE_DIR
  !error "Define STAGE_DIR (folder containing the staged RetailPOS app)"
!endif
!ifndef APP_VERSION
  !define APP_VERSION "1.0.0"
!endif
!ifndef OUT_FILE
  !define OUT_FILE "RetailPOS-Setup.exe"
!endif

!define APP_NAME "RetailPOS"
!define SERVICE_NAME "RetailPOS-PostgreSQL"
!define REG_KEY "Software\RetailPOS"
!define UNINST_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\RetailPOS"

Name "${APP_NAME}"
OutFile "${OUT_FILE}"
InstallDir "$PROGRAMFILES64\RetailPOS"
InstallDirRegKey HKLM "${REG_KEY}" "InstallDir"
RequestExecutionLevel admin
SetCompressor /SOLID lzma
SetCompressorDictSize 64
ShowInstDetails show
ShowUninstDetails show
BrandingText "${APP_NAME} ${APP_VERSION}"

VIProductVersion "${APP_VERSION}.0"
VIAddVersionKey "ProductName" "${APP_NAME}"
VIAddVersionKey "FileDescription" "${APP_NAME} Setup"
VIAddVersionKey "ProductVersion" "${APP_VERSION}"
VIAddVersionKey "FileVersion" "${APP_VERSION}"
VIAddVersionKey "LegalCopyright" "RetailPOS"

!ifdef APP_ICON
  !define MUI_ICON "${APP_ICON}"
  !define MUI_UNICON "${APP_ICON}"
!endif
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE "مرحبًا بك في تثبيت RetailPOS"
!define MUI_WELCOMEPAGE_TEXT "سيقوم هذا البرنامج بتثبيت نظام نقاط البيع وإدارة المتجر RetailPOS ${APP_VERSION}.$\r$\n$\r$\nيتضمن التثبيت: التطبيق، ومحرك PHP، وقاعدة بيانات PostgreSQL محلية تعمل كخدمة في Windows.$\r$\n$\r$\nتُحفظ بيانات المتجر في:$\r$\n$COMMONAPPDATA\RetailPOS$\r$\nولا تُحذف عند التحديث أو إلغاء التثبيت.$\r$\n$\r$\nيُنصح بإغلاق البرامج الأخرى قبل المتابعة."
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_TITLE "اكتمل التثبيت"
!define MUI_FINISHPAGE_TEXT "تم تثبيت RetailPOS.$\r$\n$\r$\nعند التثبيت لأول مرة: بيانات الدخول الأولى (البريد وكلمة المرور المؤقتة) محفوظة في الملف:$\r$\n$COMMONAPPDATA\RetailPOS\first-login.txt$\r$\nغيّر كلمة المرور بعد أول دخول."
!define MUI_FINISHPAGE_RUN "$INSTDIR\RetailPOS.exe"
!define MUI_FINISHPAGE_RUN_TEXT "تشغيل RetailPOS الآن"
!define MUI_FINISHPAGE_SHOWREADME "$COMMONAPPDATA\RetailPOS\first-login.txt"
!define MUI_FINISHPAGE_SHOWREADME_TEXT "عرض بيانات الدخول الأولى"
!define MUI_FINISHPAGE_SHOWREADME_NOTCHECKED
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "Arabic"

Var SetupCode

; ------------------------------------------------------------------ helpers

!macro StopRunningApp
  nsExec::ExecToStack 'cmd /c tasklist /FI "IMAGENAME eq RetailPOS.exe" /NH | find /I "RetailPOS.exe"'
  Pop $0
  ${If} $0 == 0
    MessageBox MB_OKCANCEL|MB_ICONEXCLAMATION "برنامج RetailPOS يعمل حاليًا وسيتم إغلاقه للمتابعة.$\r$\nتأكد من حفظ أي عملية قيد الإدخال ثم اضغط موافق." IDOK +2
    Abort
    nsExec::ExecToLog 'taskkill /IM RetailPOS.exe /T /F'
    Sleep 2000
  ${EndIf}
!macroend

Function .onInit
  ${IfNot} ${RunningX64}
    MessageBox MB_OK|MB_ICONSTOP "يتطلب RetailPOS نسخة Windows بنظام 64-بت."
    Abort
  ${EndIf}
  ${IfNot} ${AtLeastWin10}
    MessageBox MB_OK|MB_ICONSTOP "يتطلب RetailPOS نظام Windows 10 أو أحدث."
    Abort
  ${EndIf}
  SetRegView 64
  SetShellVarContext all
FunctionEnd

; The uninstaller deletes the program folder, so it must be a dedicated "...\RetailPOS" folder.
Function .onVerifyInstDir
  StrLen $0 "$INSTDIR"
  IntOp $0 $0 - 10
  StrCpy $1 "$INSTDIR" "" $0
  StrCmp $1 "\RetailPOS" +2
  Abort
FunctionEnd

; ------------------------------------------------------------------ install

Section "RetailPOS" SecMain
  SectionIn RO
  SetShellVarContext all

  !insertmacro StopRunningApp

  ; Upgrade: stop our own database service cleanly so its binaries can be replaced.
  nsExec::ExecToStack 'sc query "${SERVICE_NAME}"'
  Pop $0
  ${If} $0 == 0
    DetailPrint "إيقاف خدمة قاعدة البيانات مؤقتًا للتحديث..."
    nsExec::ExecToLog 'net stop "${SERVICE_NAME}"'
  ${EndIf}

  ; Replace old program code (business data lives elsewhere and is untouched).
  RMDir /r "$INSTDIR\resources\laravel"

  SetOutPath "$INSTDIR"
  File /r "${STAGE_DIR}\*.*"

  ; Microsoft Visual C++ 2015-2022 runtime (needed by PHP and PostgreSQL).
  DetailPrint "تثبيت مكتبات Microsoft Visual C++..."
  ExecWait '"$INSTDIR\resources\installer\vc_redist.x64.exe" /install /quiet /norestart' $0
  ${If} $0 != 0
  ${AndIf} $0 != 1638
  ${AndIf} $0 != 3010
    MessageBox MB_OK|MB_ICONEXCLAMATION "تعذّر تثبيت مكتبات Visual C++ (الرمز $0). قد لا يعمل البرنامج. ثبّتها يدويًا من ملف vc_redist.x64.exe في مجلد البرنامج."
  ${EndIf}

  ; First-run setup / upgrade (database service, .env, migrations with backup-first).
  DetailPrint "تهيئة قاعدة البيانات والإعدادات... قد يستغرق ذلك دقيقة أو أكثر."
  nsExec::ExecToLog 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\installer\setup.ps1" -InstallDir "$INSTDIR" -DataDir "$COMMONAPPDATA\RetailPOS"'
  Pop $SetupCode
  ${If} $SetupCode != 0
    MessageBox MB_OK|MB_ICONSTOP "تم نسخ ملفات البرنامج، لكن إعداد قاعدة البيانات لم يكتمل (الرمز $SetupCode).$\r$\n$\r$\nلم يتم حذف أو تغيير أي بيانات موجودة.$\r$\nراجع آخر ملف setup-*.log في:$\r$\n$COMMONAPPDATA\RetailPOS\storage\logs$\r$\n$\r$\nبعد معالجة السبب شغّل: قائمة ابدأ ← RetailPOS ← إعادة الإعداد."
  ${EndIf}

  ; Shortcuts.
  CreateShortcut "$DESKTOP\RetailPOS.lnk" "$INSTDIR\RetailPOS.exe" "" "$INSTDIR\RetailPOS.exe" 0
  CreateDirectory "$SMPROGRAMS\RetailPOS"
  CreateShortcut "$SMPROGRAMS\RetailPOS\RetailPOS.lnk" "$INSTDIR\RetailPOS.exe" "" "$INSTDIR\RetailPOS.exe" 0
  CreateShortcut "$SMPROGRAMS\RetailPOS\مجلد البيانات والنسخ الاحتياطية.lnk" "$COMMONAPPDATA\RetailPOS"
  CreateShortcut "$SMPROGRAMS\RetailPOS\إعادة الإعداد (إصلاح).lnk" "powershell.exe" '-NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\installer\repair.ps1"'
  CreateShortcut "$SMPROGRAMS\RetailPOS\إلغاء تثبيت RetailPOS.lnk" "$INSTDIR\Uninstall.exe"

  ; Registry (Apps & features).
  WriteRegStr HKLM "${REG_KEY}" "InstallDir" "$INSTDIR"
  WriteRegStr HKLM "${REG_KEY}" "Version" "${APP_VERSION}"
  WriteRegStr HKLM "${REG_KEY}" "DataDir" "$COMMONAPPDATA\RetailPOS"
  WriteRegStr HKLM "${UNINST_KEY}" "DisplayName" "RetailPOS"
  WriteRegStr HKLM "${UNINST_KEY}" "DisplayVersion" "${APP_VERSION}"
  WriteRegStr HKLM "${UNINST_KEY}" "Publisher" "RetailPOS"
  WriteRegStr HKLM "${UNINST_KEY}" "DisplayIcon" "$INSTDIR\RetailPOS.exe"
  WriteRegStr HKLM "${UNINST_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKLM "${UNINST_KEY}" "UninstallString" '"$INSTDIR\Uninstall.exe"'
  WriteRegDWORD HKLM "${UNINST_KEY}" "NoModify" 1
  WriteRegDWORD HKLM "${UNINST_KEY}" "NoRepair" 1
  ${GetSize} "$INSTDIR" "/S=0K" $0 $1 $2
  IntFmt $0 "0x%08X" $0
  WriteRegDWORD HKLM "${UNINST_KEY}" "EstimatedSize" "$0"

  WriteUninstaller "$INSTDIR\Uninstall.exe"
SectionEnd

; ---------------------------------------------------------------- uninstall

Function un.onInit
  SetRegView 64
  SetShellVarContext all
FunctionEnd

Section "Uninstall"
  SetShellVarContext all

  !insertmacro StopRunningApp

  ; Our dedicated database service runs binaries from the program folder that is
  ; being removed, so stop and unregister it. The database FILES are kept.
  nsExec::ExecToStack 'sc query "${SERVICE_NAME}"'
  Pop $0
  ${If} $0 == 0
    DetailPrint "إيقاف خدمة قاعدة البيانات (البيانات تبقى محفوظة)..."
    nsExec::ExecToLog 'net stop "${SERVICE_NAME}"'
    nsExec::ExecToLog '"$INSTDIR\resources\pgsql\bin\pg_ctl.exe" unregister -N "${SERVICE_NAME}"'
  ${EndIf}

  Delete "$DESKTOP\RetailPOS.lnk"
  RMDir /r "$SMPROGRAMS\RetailPOS"

  ; Program files only. $COMMONAPPDATA\RetailPOS (database, images, backups) is NOT removed.
  RMDir /r "$INSTDIR"

  DeleteRegKey HKLM "${UNINST_KEY}"
  DeleteRegKey HKLM "${REG_KEY}"

  MessageBox MB_OK|MB_ICONINFORMATION "تمت إزالة برنامج RetailPOS.$\r$\n$\r$\nلم يتم حذف بيانات المتجر (قاعدة البيانات، الصور، النسخ الاحتياطية) وهي باقية في:$\r$\n$COMMONAPPDATA\RetailPOS$\r$\n$\r$\nعند إعادة التثبيت سيستخدم البرنامج هذه البيانات تلقائيًا.$\r$\nإذا أردت حذفها نهائيًا: انسخ مجلد backups إلى مكان آمن أولًا، ثم احذف المجلد يدويًا."
SectionEnd
