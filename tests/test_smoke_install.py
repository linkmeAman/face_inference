"""Tests for tools.smoke_install helpers."""

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.smoke_install import free_port, venv_bin_dir, venv_executable, venv_python  # noqa: E402


class TestSmokeInstallHelpers(unittest.TestCase):
    """Unit tests for smoke test path resolution and port helpers."""

    def test_free_port(self):
        port = free_port()
        self.assertIsInstance(port, int)
        self.assertGreater(port, 0)
        self.assertLessEqual(port, 65535)

    def test_venv_path_helpers_posix(self):
        venv_dir = Path("/tmp/mock_venv")
        self.assertEqual(venv_bin_dir(venv_dir, platform="darwin"), venv_dir / "bin")
        self.assertEqual(venv_bin_dir(venv_dir, platform="linux"), venv_dir / "bin")
        self.assertEqual(venv_python(venv_dir, platform="darwin"), venv_dir / "bin" / "python")
        self.assertEqual(
            venv_executable(venv_dir, "face-inference", platform="darwin"),
            venv_dir / "bin" / "face-inference",
        )

    def test_venv_path_helpers_win32(self):
        venv_dir = Path("C:/mock_venv")
        self.assertEqual(venv_bin_dir(venv_dir, platform="win32"), venv_dir / "Scripts")
        self.assertEqual(
            venv_python(venv_dir, platform="win32"),
            venv_dir / "Scripts" / "python.exe",
        )
        self.assertEqual(
            venv_executable(venv_dir, "face-inference", platform="win32"),
            venv_dir / "Scripts" / "face-inference.exe",
        )


if __name__ == "__main__":
    unittest.main()
