@echo off
rem Starts the hospital's reading computer on Windows. Double-click this file, and leave the window open.
cd /d "%~dp0"
if not exist venv\Scripts\python.exe (
  echo The reading program is not installed yet. Follow README.md in this folder first.
  pause
  exit /b 1
)
venv\Scripts\python.exe reader.py
pause
