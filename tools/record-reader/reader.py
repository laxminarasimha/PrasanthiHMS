#!/usr/bin/env python3
"""
The hospital's reading computer.

Scanned papers are uploaded to the hospital's web system, but the web server cannot run a reading
program. This script runs on one ordinary computer inside the hospital that is left switched on.
It asks the web system for the next scanned paper, reads it with Marker (which works entirely on
this computer; nothing is sent anywhere else), and hands the text back. A person at the hospital
then checks that text against the scan before anything is kept in the patient's record.

    python reader.py                 read papers as they arrive, until stopped
    python reader.py --check         only check that the web system accepts this computer's key
    python reader.py --file a.pdf    read one file from this computer and print the text (a trial run)

Settings are in reader.ini beside this script (see reader.example.ini):

    [reader]
    server = https://hms.example.com
    key = the key shown once under Setup → Hospital → Reading scanned papers

Scans are patient records: they are kept in a temporary folder only while being read and are
deleted straight after. Nothing about a patient is printed on the screen or written to a log.
"""

from __future__ import annotations

import argparse
import configparser
import json
import os
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent

# How long to wait before asking again when nothing is waiting, and after a failed call.
IDLE_SECONDS = 20
RETRY_SECONDS = 60

# The file endings Marker is given, by the type the web system reports.
ENDINGS = {"application/pdf": ".pdf", "image/jpeg": ".jpg", "image/png": ".png", "image/webp": ".webp"}

# Marker's own settings. "fast" reads on an ordinary processor with no graphics card and no helper
# program. On a computer with an NVIDIA graphics card, "balanced" reads tables and poor scans better.
MODE = os.environ.get("READER_MODE", "fast")


def say(words: str) -> None:
    print(time.strftime("%H:%M:%S"), words, flush=True)


class Stop(Exception):
    """Something only a person can put right: the message says what."""


def settings() -> tuple[str, str]:
    file = HERE / "reader.ini"
    parser = configparser.ConfigParser()
    if file.exists():
        parser.read(file, encoding="utf-8")
    server = os.environ.get("READER_SERVER") or parser.get("reader", "server", fallback="")
    key = os.environ.get("READER_KEY") or parser.get("reader", "key", fallback="")
    server = server.strip().rstrip("/")
    key = key.strip()
    if not server or not key:
        raise Stop(f"Fill in the server address and the key in {file} (copy reader.example.ini to start).")
    if not server.startswith("https://") and not server.startswith(("http://localhost", "http://127.0.0.1")):
        raise Stop("The server address must start with https:// so that scans travel safely.")
    return server, key


class Desk:
    """The web system, as this computer sees it."""

    def __init__(self, server: str, key: str) -> None:
        self.api = server + "/api/reader"
        self.key = key

    def _call(self, path: str, body: dict | None = None, timeout: int = 60) -> bytes:
        request = urllib.request.Request(self.api + path, method="GET" if body is None else "POST")
        request.add_header("X-Reader-Key", self.key)
        request.add_header("Accept", "application/json")
        data = None
        if body is not None:
            # Always a body, even an empty one: some hosts refuse a POST that carries nothing.
            data = json.dumps(body).encode("utf-8")
            request.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(request, data=data, timeout=timeout) as reply:
                return reply.read()
        except urllib.error.HTTPError as error:
            if error.code == 401:
                raise Stop("The web system did not accept this computer's key. Make a new key under Setup → Hospital → Reading scanned papers and put it in reader.ini.") from None
            raise

    def hello(self) -> dict:
        return json.loads(self._call("/hello", {}))

    def next(self) -> dict:
        return json.loads(self._call("/next", {}))

    def page(self, page_id: int) -> bytes:
        return self._call(f"/pages/{page_id}", timeout=180)

    def done(self, record_id: int, text: str = "", error: str = "") -> None:
        self._call(f"/records/{record_id}/done", {"text": text, "error": error[:480]})


class Marker:
    """Marker with its reading models loaded once and kept ready."""

    def __init__(self) -> None:
        say("Loading the reading program. The first time, it downloads its models, which can take several minutes…")
        try:
            from marker.config.parser import ConfigParser
            from marker.models import create_model_dict
            from marker.output import text_from_rendered
        except ImportError:
            raise Stop('Marker is not installed. Run:  pip install "marker-pdf[full]"') from None

        self._text = text_from_rendered
        self._config = ConfigParser({"mode": MODE, "output_format": "markdown", "disable_image_extraction": True, "disable_tqdm": True})
        in_process = MODE in ("fast", "indra", "no_ocr")
        self._models = create_model_dict(load_indra=MODE not in ("vllm", "surya", "accurate"), no_server=in_process)
        say(f"Ready to read (mode: {MODE}).")

    def read(self, path: Path) -> str:
        config = self._config
        converter = config.get_converter_cls()(
            config=config.generate_config_dict(),
            artifact_dict=self._models,
            processor_list=config.get_processors(),
            renderer=config.get_renderer(),
            llm_service=config.get_llm_service(),
        )
        text, _, _ = self._text(converter(str(path)))
        return text.strip()


def read_paper(desk: Desk, marker: Marker, paper: dict) -> None:
    record_id = paper["id"]
    pages = paper.get("pages", [])
    say(f"Paper {record_id}: reading {len(pages)} file(s)…")
    started = time.time()
    parts: list[str] = []
    try:
        # The scans live in a folder that is removed, with everything in it, as soon as reading ends.
        with tempfile.TemporaryDirectory(prefix="reader-") as folder:
            for number, page in enumerate(pages, start=1):
                ending = ENDINGS.get(page.get("mime", ""))
                if not ending:
                    continue
                path = Path(folder) / f"page{number}{ending}"
                path.write_bytes(desk.page(page["id"]))
                parts.append(marker.read(path))
    except Stop:
        raise
    except urllib.error.URLError:
        # The web system could not be reached part-way: leave the paper; it is offered again later.
        say(f"Paper {record_id}: the connection dropped. It will be tried again.")
        raise
    except Exception as error:  # noqa: BLE001 - whatever went wrong, the desk is told so the paper is not left waiting
        say(f"Paper {record_id}: could not be read ({type(error).__name__}).")
        desk.done(record_id, error=f"The reading program could not read this scan ({type(error).__name__}).")
        return

    text = "\n\n".join(part for part in parts if part)
    if not text:
        desk.done(record_id, error="Nothing could be read from this scan. Check that it is not blank or upside down.")
    else:
        desk.done(record_id, text=text)
    say(f"Paper {record_id}: done in {time.time() - started:.0f} s.")


def main() -> int:
    parser = argparse.ArgumentParser(description="Reads scanned papers for the hospital's web system, on this computer.")
    parser.add_argument("--check", action="store_true", help="only check that the web system accepts this computer's key")
    parser.add_argument("--file", help="read one file from this computer and print the text, without the web system")
    parser.add_argument("--once", action="store_true", help="read the papers waiting now, then stop")
    args = parser.parse_args()

    try:
        if args.file:
            print(Marker().read(Path(args.file)))
            return 0

        desk = Desk(*settings())
        if args.check:
            waiting = desk.hello().get("waiting", 0)
            say(f"The web system accepted this computer's key. Papers waiting to be read: {waiting}.")
            return 0

        marker = Marker()
        say("Waiting for scanned papers. Leave this window open. Press Ctrl+C to stop.")
        while True:
            try:
                paper = desk.next()
                if paper.get("id"):
                    read_paper(desk, marker, paper)
                    continue
                if args.once:
                    return 0
                time.sleep(IDLE_SECONDS)
            except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
                say(f"Could not reach the web system ({type(error).__name__}). Trying again in a minute.")
                time.sleep(RETRY_SECONDS)
    except Stop as stop:
        say(str(stop))
        return 1
    except KeyboardInterrupt:
        say("Stopped.")
        return 0


if __name__ == "__main__":
    sys.exit(main())
