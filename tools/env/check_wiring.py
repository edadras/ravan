#!/usr/bin/env python3
"""Check that every setting an operator can put in .env actually reaches the
service that reads it.

A mis-wired setting has no symptom. The service starts, uses its built-in
default, and nothing in any log says the operator's value was ignored. Three
such mismatches were already live in this stack:

  * RAVAN_ASR_BACKEND was documented and read by the backend's config, but
    docker-compose passed it only to the asr service — so switching to the
    remote engine never updated the transcription consent text.
  * asr-service read RAVAN_ASR_COMPUTE while .env.example and compose said
    RAVAN_ASR_COMPUTE_TYPE.
  * Settings were substituted by compose but never documented, so an operator
    had no way to learn the knob existed.

The contract this enforces is narrow and mechanical:

  1. A variable documented in .env.example must be delivered to every service
     whose code reads it.
  2. A variable compose takes from .env must be documented in .env.example.
  3. A variable documented in .env.example that nothing reads or substitutes
     is a lie in the install guide.
  4. env() outside backend/config returns null once `php artisan config:cache`
     has run, which is what production does.

Settings read only by code and left out of .env.example are deliberate
internal defaults (a fallback path inside the image, a legacy spelling kept
for compatibility) and are not the operator's business, so they are not
checked. Document one and it becomes a promise this script holds you to.

    python3 tools/env/check_wiring.py [--verbose]

Exit status 0 when everything lines up, 1 otherwise.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]

# Which compose services run which source tree. A documented variable read by
# the code in a tree has to reach every service listed for it.
SERVICES = {
    "backend/config": ["backend", "worker", "scheduler", "reverb"],
    "analysis-service/app": ["analysis"],
    "asr-service/app": ["asr"],
}

# Settings that come from somewhere other than .env: compose fixes them per
# service, or they are paths inside the image.
NOT_FROM_ENV = {"RAVAN_ROLE", "HF_HOME"}

# Framework settings (APP_*, DB_*, MAIL_*, ...) are Laravel's own and already
# have working defaults; these prefixes cover what this project added.
INTERESTING = ("RAVAN_", "OPENAI_", "ANTHROPIC_", "LIVEKIT_")

PHP_ENV = re.compile(r"""\benv\(\s*['"]([A-Z0-9_]+)['"]""")
PY_ENV = re.compile(r"""os\.environ(?:\.get\(|\[)\s*['"]([A-Z0-9_]+)['"]""")
COMPOSE_REF = re.compile(r"\$\{([A-Z0-9_]+)[:\-?}]")


def interesting(name: str) -> bool:
    return name.startswith(INTERESTING) and name not in NOT_FROM_ENV


def read_by_code() -> dict[str, dict[str, str]]:
    """{variable: {service: "path:line where it is read"}}."""
    found: dict[str, dict[str, str]] = {}
    for tree_name, service_names in SERVICES.items():
        for path in sorted((ROOT / tree_name).rglob("*")):
            if path.suffix not in {".php", ".py"} or not path.is_file():
                continue
            pattern = PHP_ENV if path.suffix == ".php" else PY_ENV
            text = path.read_text(encoding="utf-8")
            for line_no, line in enumerate(text.splitlines(), 1):
                for name in pattern.findall(line):
                    if not interesting(name):
                        continue
                    where = f"{path.relative_to(ROOT)}:{line_no}"
                    for service in service_names:
                        found.setdefault(name, {}).setdefault(service, where)
    return found


def compose_services() -> dict[str, dict[str, str]]:
    """Each service's environment and build args, with anchors merged."""
    compose = yaml.safe_load((ROOT / "docker-compose.yml").read_text())
    out: dict[str, dict[str, str]] = {}
    for name, service in (compose.get("services") or {}).items():
        env = service.get("environment") or {}
        if isinstance(env, list):  # `- KEY=value` form
            env = dict(i.split("=", 1) if "=" in i else (i, "") for i in env)
        args = (service.get("build") or {}).get("args") or {}
        if isinstance(args, list):
            args = dict(i.split("=", 1) if "=" in i else (i, "") for i in args)
        out[name] = {
            k: "" if v is None else str(v)
            for k, v in {**env, **{f"build.{k}": v for k, v in args.items()}}.items()
        }
    return out


def documented() -> set[str]:
    """Settings .env.example tells the operator about."""
    names = set()
    for line in (ROOT / ".env.example").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            names.add(line.split("=", 1)[0].strip())
    return names


def uncached_env_calls() -> list[str]:
    """env() outside backend/config — returns null under `config:cache`."""
    hits = []
    for path in sorted((ROOT / "backend").rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        if rel.startswith(("backend/config/", "backend/vendor/", "backend/tests/")):
            continue
        for line_no, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            if line.lstrip().startswith(("//", "*", "#")):
                continue
            for name in PHP_ENV.findall(line):
                hits.append(f"{rel}:{line_no}  env('{name}')")
    return hits


def main() -> int:
    parser = argparse.ArgumentParser(description="Check .env wiring.")
    parser.add_argument("--verbose", action="store_true", help="list every setting checked")
    args = parser.parse_args()

    services = compose_services()
    reads = read_by_code()
    docs = documented()
    problems: list[str] = []

    substituted: dict[str, set[str]] = {}
    for service, values in services.items():
        for value in values.values():
            for name in COMPOSE_REF.findall(value):
                substituted.setdefault(name, set()).add(service)

    # 1. A documented setting reaches every service that reads it.
    checked = sorted(name for name in docs if interesting(name))
    for name in checked:
        for service, where in sorted(reads.get(name, {}).items()):
            if service not in services:
                problems.append(f"docker-compose.yml has no service `{service}`")
            elif name not in services[service]:
                problems.append(
                    f"{name} is documented and read by {where}, but service "
                    f"`{service}` never receives it — an operator who sets it "
                    f"gets the default with no warning"
                )
        if args.verbose:
            targets = ", ".join(sorted(reads.get(name, {}))) or "compose only"
            print(f"  {name:<36} {targets}")

    # 2. What compose takes from .env has to be documented.
    for name, where in sorted(substituted.items()):
        if interesting(name) and name not in docs:
            problems.append(
                f"{name} is taken from .env by {', '.join(sorted(where))} but "
                f".env.example does not document it"
            )

    # 3. What .env.example promises has to be used.
    for name in checked:
        if name not in reads and name not in substituted:
            problems.append(
                f"{name} is documented in .env.example but no service reads or "
                f"substitutes it"
            )

    # 4. env() survives config caching only inside config/.
    for hit in uncached_env_calls():
        problems.append(
            f"{hit} is outside backend/config — env() returns null once "
            f"`php artisan config:cache` has run, which production does"
        )

    if problems:
        print("environment wiring: PROBLEMS")
        for problem in sorted(set(problems)):
            print(f"  - {problem}")
        return 1

    print(f"environment wiring: ok ({len(checked)} settings, {len(services)} services)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
