#!/usr/bin/env python3
"""Сборка должна остановиться, если изменена логика исходных проверок Интернета."""
import json
import sys
from patch_mdiag import replace_xdiag_origins

before, after, base = sys.argv[1:]
markers = ('getActiveNetwork', 'hasCapability(I)', 'isConnected()Z', 'isAvailable()Z', 'getAllNetworks')
def checks(path):
    rows = json.load(open(path))['network_checks']
    return {(r['file'], r['method'].splitlines()[0]): r['method'] for r in rows
            if any(m in r['method'] for m in markers)}
original, patched = checks(before), checks(after)
assert original, 'No original network checks found'
for key, body in original.items():
    # Допускается только уже заявленная замена URL поставщика внутри того же метода.
    expected, _ = replace_xdiag_origins(body, 'https://services.x-diag.info', base)
    assert patched.get(key) == expected, 'Network connectivity check changed: ' + str(key)
print('Original Internet-check methods preserved:', len(original))
