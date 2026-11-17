#!/usr/bin/env bash
# Starts the hospital's reading computer on a Mac or Linux machine. Leave the window open.
cd "$(dirname "$0")" || exit 1
[[ -x venv/bin/python ]] || { echo "The reading program is not installed yet. Follow README.md in this folder first."; exit 1; }
exec venv/bin/python reader.py
