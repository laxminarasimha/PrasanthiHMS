# The reading computer

Patients bring old papers: prescriptions, lab reports, discharge summaries. Staff scan them on the
patient's page in the hospital system. Something then has to read each scan into a draft that a
person checks before it is filed.

The hospital's web server cannot run a reading program, so one ordinary computer inside the
hospital does it. That computer runs this small program, which:

1. asks the hospital system for the next scanned paper,
2. reads it with **Marker**, a free reading program that works entirely on that computer,
3. hands the text back, and deletes its copy of the scan.

Nothing is sent anywhere except between this computer and the hospital system. Printed papers are
read well. Handwriting is not; for those pages staff can use "Send to AI reading" on the checking
screen, if the hospital has switched that on.

If this computer is switched off, nothing is lost: scanned papers wait, and are read when it is
switched on again. Staff can always type a paper in by hand in the meantime.

## What the computer needs

- Windows 10 or 11, a Mac, or Linux. No graphics card is needed.
- 8 GB of memory or more, and about 6 GB of free disk space.
- An internet connection, and to be left switched on during working hours (set it not to sleep).
- Python 3.10 or newer (from python.org; on Windows tick "Add Python to PATH" while installing).

## Setting it up (once, about half an hour)

1. Copy this whole `record-reader` folder to the computer, for example to `C:\record-reader`.

2. Open a command window in that folder and install Marker into a folder of its own:

   Windows:

   ```
   python -m venv venv
   venv\Scripts\pip install "marker-pdf[full]"
   ```

   Mac or Linux:

   ```
   python3 -m venv venv
   venv/bin/pip install "marker-pdf[full]"
   ```

   This downloads about 2 GB and takes a while.

3. In the hospital system, an admin opens **Setup → Hospital → Reading scanned papers** and presses
   **Make a key for the reading computer**. The key is shown once.

4. Copy `reader.example.ini` to `reader.ini`, and put the hospital system's address and that key in
   it. Do not send the key by message or email; type or paste it on the reading computer itself.

5. Check that the hospital system accepts the key:

   ```
   venv\Scripts\python reader.py --check          (Windows)
   venv/bin/python reader.py --check              (Mac or Linux)
   ```

6. Try it on any printed page of your own that has no patient on it, to see what it makes of it:

   ```
   venv\Scripts\python reader.py --file some-page.pdf
   ```

   The first run downloads Marker's reading models (about 2.5 GB more) and can take several
   minutes. After that a page takes a second or two.

## Running it every day

Double-click `start-reader.bat` (Windows) or run `./start-reader.sh` (Mac or Linux), and leave the
window open. To have it start by itself, put a shortcut to `start-reader.bat` in the Windows
Startup folder (press Win+R, type `shell:startup`).

The window shows a line for each paper it reads: its number and how long it took. It never shows
a patient's name or anything from the paper.

Under **Setup → Hospital → Reading scanned papers** the hospital system shows whether this
computer is switched on and when it last called in.

## If something is wrong

| What you see | What to do |
|---|---|
| "The web system did not accept this computer's key" | Make a new key under Setup and put it in `reader.ini`. A new key always replaces the old one. |
| "Could not reach the web system" | The internet is down or the address in `reader.ini` is wrong. It keeps trying by itself. |
| "Marker is not installed" | Repeat step 2. |
| A paper shows "Could not be read" in the hospital system | The scan is blank, upside down or too faint. Scan it again, or type it in. |
| The computer is to be replaced or was stolen | Press "Switch the reading computer off" under Setup. Its key stops working at once. |

## Better reading on a stronger computer

By default Marker runs in its "fast" mode, which needs no graphics card. On a computer with an
NVIDIA graphics card, its "balanced" mode reads tables and poor scans better. To use it, set the
environment variable `READER_MODE=balanced` before starting the reader. See Marker's own notes at
https://github.com/datalab-to/marker for what that mode needs.

## About Marker's licence

Marker's program is free to use. Its reading models are free for research, personal use, and
organisations with under US $5 million a year in revenue or funding; larger organisations need a
paid licence from its makers (https://www.datalab.to). Check which applies to the hospital.
