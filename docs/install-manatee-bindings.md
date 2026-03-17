# Installing Manatee Python bindings

The Manatee backend needs the official Python bindings built from **manatee-open** (not the unrelated PyPI package `manatee`). You need `manatee.py` and the native extension `_manatee.so`. For **server-wide** use, configure once so all projects can use the Manatee backend.

## Option 1: Install into the server's Python (recommended)

Build manatee-open, then install into the same Python that runs flexicorp (e.g. the shared venv or system Python used by your web server):

```bash
cd /path/to/manatee-open-2.225.8
./configure PYTHON=/path/to/python   # e.g. your TEITOK venv or system python3
make install
```

The bindings go into that Python's site-packages. No path or env var needed; all projects on the server can use Manatee.

### Manual copy into a venv (if `make install` is not used)

If you already built the bindings and only want to copy the files so that **your venv’s Python** always sees them (no `MANATEE_API` or `PYTHONPATH`):

1. Get your venv’s site-packages directory (with the venv activated):
   ```bash
   python3 -c "import site; print(site.getsitepackages()[0])"
   ```
   Example: `/Users/you/programming/flexicorp/venv/lib/python3.11/site-packages`

2. Copy the Manatee Python module and the compiled extension into that directory:
   ```bash
   API=/Users/mjanssen/programming/flexicorp/git/manatee-open-2.225.8/api
   SITE=$(python3 -c "import site; print(site.getsitepackages()[0])")

   cp "$API/manatee.py" "$SITE/"
   cp "$API/.libs/_manatee.so" "$SITE/"
   ```

3. Check that the venv sees the bindings:
   ```bash
   python3 -c "import manatee; print(manatee.version())"
   ```

After that, any script run with that venv’s Python (e.g. `flexicorp.cli`) will find `manatee` without setting `MANATEE_API` or `PYTHONPATH`. Use your real paths for `API` and your actual corpus `--registry` and `--corpus` when calling flexicorp.

## Option 2: Set MANATEE_API server-wide

Point the server to one manatee-open `api` directory for all projects. In the environment that runs the web server (e.g. Apache, PHP, or the shell that starts the app), set:

```bash
export MANATEE_API=/path/to/manatee-open-2.225.8/api
```

The directory must contain `manatee.py` and `.libs/_manatee.so` (or `_manatee.so` in the same directory). Flexicorp will use it for every project.

## Option 3: Install under a standard path

If you install the api directory at `/usr/local/share/manatee/api` or `/opt/share/manatee/api` (with `manatee.py` and `.libs/_manatee.so`), flexicorp will find it automatically with no environment variables.

## Option 4: Per-project path (override)

To use different bindings for a single project, set **manatee/pythonpath** in that project's TEITOK settings to the api path (e.g. `/path/to/api/.libs:/path/to/api`), or copy the built `api/` directory to `project_root/lib/manatee/`.
