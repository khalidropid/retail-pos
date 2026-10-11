#!/usr/bin/env python3
"""Extract only the needed parts of the official PostgreSQL Windows binaries zip.

The EDB zip is ~340 MB, of which ~275 MB is pgAdmin (not used by RetailPOS).
This reads the zip's central directory over HTTPS range requests and downloads
only pgsql/bin, pgsql/lib, pgsql/share and the license files. Every file's
CRC32 is checked against the zip, and a manifest hash (sha256 over the sorted
"sha256  path" lines of all extracted files) is printed so build.sh can pin it.

    fetch-pg.py URL OUT_DIR     # resumable: completed files are kept
"""
import hashlib, io, os, sys, time, urllib.request, zipfile, zlib

KEEP = ('pgsql/bin/', 'pgsql/lib/', 'pgsql/share/')
CHUNK = 8 * 1024 * 1024


def http(url, headers=None, method='GET'):
    for attempt in range(20):
        try:
            return urllib.request.urlopen(urllib.request.Request(url, headers=headers or {}, method=method), timeout=120)
        except Exception as e:  # network hiccups: retry
            if attempt == 19:
                raise
            print(f'  retry ({e})', file=sys.stderr)
            time.sleep(5)


class HttpFile(io.RawIOBase):
    """Seekable read-only file over HTTP range requests, with a read-ahead buffer."""

    def __init__(self, url):
        self.url, self.pos = url, 0
        self.size = int(http(url, method='HEAD').headers['Content-Length'])
        self.buf_start, self.buf = 0, b''

    def seekable(self): return True
    def readable(self): return True
    def tell(self): return self.pos

    def seek(self, off, whence=0):
        self.pos = off if whence == 0 else self.pos + off if whence == 1 else self.size + off
        return self.pos

    def _fill(self, n):
        end = min(self.pos + max(n, CHUNK), self.size) - 1
        for attempt in range(20):
            try:
                data = http(self.url, {'Range': f'bytes={self.pos}-{end}'}).read()
                if len(data) == end - self.pos + 1:
                    break
            except Exception as e:
                print(f'  retry ({e})', file=sys.stderr)
            time.sleep(5)
        else:
            raise IOError('range download failed')
        self.buf_start, self.buf = self.pos, data

    def read(self, n=-1):
        if n < 0:
            n = self.size - self.pos
        if n == 0 or self.pos >= self.size:
            return b''
        off = self.pos - self.buf_start
        if not (0 <= off and off + n <= len(self.buf)) and not (0 <= off < len(self.buf) and self.buf_start + len(self.buf) == self.size):
            self._fill(n)
            off = 0
        data = self.buf[off:off + n]
        self.pos += len(data)
        return data


def crc_of(path):
    crc = 0
    with open(path, 'rb') as f:
        for block in iter(lambda: f.read(1 << 20), b''):
            crc = zlib.crc32(block, crc)
    return crc


def main(url, out):
    z = zipfile.ZipFile(HttpFile(url))
    wanted = [i for i in z.infolist() if not i.is_dir() and (
        i.filename.startswith(KEEP) or (i.filename.count('/') == 1 and i.filename.endswith('.txt')))]
    wanted.sort(key=lambda i: i.header_offset)  # sequential reads -> read-ahead hits
    total = sum(i.compress_size for i in wanted)
    done = 0
    for i in wanted:
        dest = os.path.join(out, *i.filename.split('/'))
        if not (os.path.exists(dest) and os.path.getsize(dest) == i.file_size and crc_of(dest) == i.CRC):
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            with z.open(i) as src, open(dest + '.tmp', 'wb') as dst:  # zipfile verifies CRC at EOF
                while block := src.read(1 << 20):
                    dst.write(block)
            os.replace(dest + '.tmp', dest)
        done += i.compress_size
        print(f'\r  {done / 1e6:6.1f} / {total / 1e6:.1f} MB', end='', file=sys.stderr, flush=True)
    print(file=sys.stderr)
    print(manifest_hash(out))


def manifest_hash(out):
    lines = []
    for root, _, files in os.walk(out):
        for name in files:
            if name.endswith('.tmp'):
                continue
            p = os.path.join(root, name)
            rel = os.path.relpath(p, out).replace(os.sep, '/')
            with open(p, 'rb') as f:
                lines.append(f'{hashlib.sha256(f.read()).hexdigest()}  {rel}')
    return hashlib.sha256('\n'.join(sorted(lines)).encode()).hexdigest()


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == '--hash':
        print(manifest_hash(sys.argv[2]))
    elif len(sys.argv) == 3:
        main(sys.argv[1], sys.argv[2])
    else:
        sys.exit(__doc__)
