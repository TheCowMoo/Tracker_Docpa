# -*- mode: python ; coding: utf-8 -*-
#
# DOCPA Tracker — PyInstaller Build Specification
#
# Build with:
#   pyinstaller build.spec
#
# This creates a single-file .exe in the dist/ directory.
# The config.json is embedded as a binary dependency so it's created fresh on first run.

import os
import sys

block_cipher = None

# Collect all client Python files
a = Analysis(
    ['main.py'],
    pathex=[os.path.dirname(__file__)],
    binaries=[],
    datas=[
        # (source, destination in bundle)
        # config.json will be created on first run if missing
    ],
    hiddenimports=[
        'tracker',
        'interface',
        'pyautogui',
        'PIL',
        'PIL.Image',
        'PIL.ImageDraw',
        'requests',
        'pystray',
        'ctypes',
        'winreg',
        'queue',
        'threading',
        'logging',
        'json',
        'datetime',
        'webbrowser',
        'tkinter',
        'tkinter.ttk',
        'tkinter.messagebox',
        'tkinter.simpledialog',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        'matplotlib',
        'numpy',
        'pandas',
        'scipy',
        'PyQt5',
        'PySide2',
        'PySide6',
        'PyQt6',
        'notebook',
        'ipython',
        'jupyter',
    ],
    noarchive=False,
    optimize=1,
)

pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name='DOCPA_Tracker',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,          # No console window — runs in tray
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=None,              # Add a .ico file here if desired
)

# Also create a one-folder bundle for debugging
# (uncomment if needed)
#coll = COLLECT(
#    exe,
#    a.binaries,
#    a.datas,
#    strip=False,
#    upx=True,
#    upx_exclude=[],
#    name='DOCPA_Tracker',
#)