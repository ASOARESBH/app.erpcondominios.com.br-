"""Ferramentas locais e sintéticas da migração PostgreSQL do ERP."""

import os

if os.geteuid() == 0 and os.environ.get("ERP_P2_ROOT_LAUNCHER_VERIFIED") != "1":
    raise SystemExit("P2_PACKAGE_ROOT_IMPORT_REQUIRES_REBUILD_RUNNER")
