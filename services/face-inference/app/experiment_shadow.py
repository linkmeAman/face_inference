"""Isolated one-worker shadow queue. It never affects primary inference."""
from __future__ import annotations

import queue
import threading
import time
from typing import Callable, Optional


class ShadowQueue:
    def __init__(self, work: Callable[[object], None], limit: int = 8, timeout_ms: int = 1500) -> None:
        self._work, self._timeout = work, timeout_ms / 1000.0
        self._queue: "queue.Queue[object]" = queue.Queue(maxsize=limit)
        self.failures = 0
        self._thread = threading.Thread(target=self._run, name="face-experiment-shadow", daemon=True)
        self._thread.start()

    def submit(self, item: object) -> bool:
        try:
            self._queue.put_nowait(item)
            return True
        except queue.Full:
            self.failures += 1
            return False

    def _run(self) -> None:
        while True:
            item = self._queue.get()
            started = time.monotonic()
            try:
                self._work(item)
                if time.monotonic() - started > self._timeout:
                    self.failures += 1
            except Exception:
                self.failures += 1
            finally:
                self._queue.task_done()


def run() -> None:
    """Keep the isolated one-worker pool available for a verified challenger.

    No challenger manifests are enabled in this slice, so the process accepts
    no external payload and performs no model download or fallback.  The queue
    implementation is separately exercised by the logger scenario.
    """
    worker = ShadowQueue(lambda _item: None, limit=8, timeout_ms=1500)
    threading.Event().wait()


if __name__ == "__main__":
    run()
