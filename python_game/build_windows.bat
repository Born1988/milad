@echo off
REM Build a standalone plane_dodge.exe on Windows.
REM Requires Python 3.9+ installed and available as "python" on PATH.

python -m pip install --upgrade pip
python -m pip install -r requirements.txt pyinstaller
python -m PyInstaller --onefile --name plane_dodge plane_dodge.py

echo.
echo Done. Find plane_dodge.exe in the dist\ folder.
pause
