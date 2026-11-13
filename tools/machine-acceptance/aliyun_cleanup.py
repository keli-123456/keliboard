"""Embed the pinned PHP factory; no SSH, provider or application calls here."""
from pathlib import Path
import hashlib

_bytes = Path(__file__).with_suffix('.php').read_bytes()
SOURCE_SHA256 = hashlib.sha256(_bytes).hexdigest()
_source = _bytes.decode('utf-8')
if not _source.startswith('<?php\n') or '\r' in _source:
    raise RuntimeError('cleanup_reader_source_invalid')
CLEANUP_DNS_PHP = '$readDns=(static function(){\n' + _source[len('<?php\n'):] + '\n})();\n'
CLEANUP_DNS_PHP += '$readDns=$readDns($dns,$cleanupRemaining);\n'
