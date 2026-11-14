#!/usr/bin/env python3
"""Tests for the generated Nginx clean-page route allowlist."""

from __future__ import annotations

import importlib.util
import re
import tempfile
import unittest
from pathlib import Path
from html.parser import HTMLParser

SCRIPT = Path(__file__).with_name("generate-clean-page-routes.py")
spec = importlib.util.spec_from_file_location("clean_page_routes", SCRIPT)
assert spec and spec.loader
routes = importlib.util.module_from_spec(spec)
spec.loader.exec_module(routes)


class CleanPageRouteTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.pages = self.root / "frontend" / "pages"
        self.pages.mkdir(parents=True)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def add_page(self, name: str) -> None:
        (self.pages / f"{name}.html").write_text("<div></div>", encoding="utf-8")

    def test_generation_is_deterministic_and_anchored(self) -> None:
        self.add_page("moradores")
        self.add_page("ordens_servico")
        self.add_page("painel-2")
        output = routes.render(self.pages, self.root)
        self.assertEqual(output, routes.render(self.pages, self.root))
        self.assertIn("location ~ ^/(moradores|ordens_servico|painel-2)/?$", output)
        self.assertIn("try_files /frontend/layout-base.html =404;", output)
        self.assertIn('add_header Cache-Control "no-cache, no-store, must-revalidate";', output)
        self.assertNotIn("return 308", output)
        self.assertNotIn("^/.*", output)

    def test_reserved_routes_fail_closed(self) -> None:
        for reserved in ("api", "uploads", "config", "frontend", "projetos", "login_fornecedor"):
            with self.subTest(reserved=reserved):
                self.add_page(reserved)
                with self.assertRaises(ValueError):
                    routes.route_slugs(self.pages, self.root)
                (self.pages / f"{reserved}.html").unlink()

    def test_invalid_case_duplicate_and_symlink_fail_closed(self) -> None:
        self.add_page("UpperCase")
        with self.assertRaises(ValueError):
            routes.route_slugs(self.pages, self.root)
        (self.pages / "UpperCase.html").unlink()

        self.add_page("moradores")
        duplicate = self.pages / "MORADORES.html"
        duplicate.write_text("<div></div>", encoding="utf-8")
        with self.assertRaises(ValueError):
            routes.route_slugs(self.pages, self.root)
        duplicate.unlink()

        link = self.pages / "symlinked.html"
        link.symlink_to(self.pages / "moradores.html")
        with self.assertRaises(ValueError):
            routes.route_slugs(self.pages, self.root)

    def test_root_file_or_directory_collision_fails_closed(self) -> None:
        self.add_page("moradores")
        (self.root / "moradores").mkdir()
        with self.assertRaises(ValueError):
            routes.route_slugs(self.pages, self.root)

    def test_empty_source_fails_closed(self) -> None:
        with self.assertRaises(ValueError):
            routes.route_slugs(self.pages, self.root)

    def test_repository_snippet_is_current_and_excludes_reserved_paths(self) -> None:
        repo = Path(__file__).resolve().parents[2]
        output = repo / "deploy" / "hetzner" / "erp-pages.conf"
        generated = routes.render(repo / "frontend" / "pages", repo)
        self.assertEqual(output.read_text(encoding="utf-8"), generated)
        patterns = [line for line in generated.splitlines() if line.startswith("location ~ ^/(")]
        self.assertEqual(len(patterns), 1)
        for reserved in ("api", "uploads", "config", "frontend", "projetos", "login_morador"):
            for pattern in patterns:
                self.assertNotIn(f"|{reserved}|", pattern)
                self.assertNotIn(f"({reserved}|", pattern)
                self.assertNotIn(f"|{reserved})", pattern)

    def test_all_page_slugs_match_and_sensitive_paths_do_not(self) -> None:
        repo = Path(__file__).resolve().parents[2]
        generated = routes.render(repo / "frontend" / "pages", repo)
        pattern = re.compile(generated.splitlines()[2].split("~", 1)[1].rsplit(" {", 1)[0].strip())
        slugs = routes.route_slugs(repo / "frontend" / "pages", repo)
        for slug in slugs:
            with self.subTest(slug=slug):
                self.assertRegex(f"/{slug}", pattern)
                self.assertRegex(f"/{slug}/", pattern)
        for path in (
            "/", "/api", "/api/api_login.php", "/uploads/a.png", "/config",
            "/frontend/layout-base.html", "/projetos", "/projetos/acme/123",
            "/dashboard.html", "/moradores/extra", "/protocolo.php",
        ):
            with self.subTest(path=path):
                self.assertIsNone(pattern.match(path))

    def test_layout_and_shell_fetch_paths_are_root_absolute(self) -> None:
        repo = Path(__file__).resolve().parents[2]
        layout_path = repo / "frontend" / "layout-base.html"

        class RelativeResourceParser(HTMLParser):
            def __init__(self) -> None:
                super().__init__()
                self.relative: list[str] = []

            def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
                for key, value in attrs:
                    if key in ("src", "href") and value and not value.startswith(("/", "//", "http:", "https:")):
                        self.relative.append(f"{tag}.{key}={value}")

        parser = RelativeResourceParser()
        layout = layout_path.read_text(encoding="utf-8")
        parser.feed(layout)
        self.assertEqual(parser.relative, [])
        self.assertIn("import { AppRouter } from '/frontend/js/app-router.js?", layout)

        shell_paths = {
            "frontend/components/user-menu/user-menu.css",
            "frontend/components/user-menu/user-menu.js",
            "frontend/components/sidebar.html",
            "frontend/i18n/pt-BR.json",
        }
        for relpath in shell_paths:
            with self.subTest(path=relpath):
                self.assertTrue((repo / relpath).is_file())

        i18n = (repo / "frontend/js/i18n.js").read_text(encoding="utf-8")
        sidebar = (repo / "frontend/js/sidebar-controller.js").read_text(encoding="utf-8")
        self.assertIn("/frontend/i18n/${state.locale}.json", i18n)
        self.assertIn("/frontend/components/sidebar.html", sidebar)

    def test_both_vhosts_include_allowlist_after_denials_and_keep_special_routes(self) -> None:
        repo = Path(__file__).resolve().parents[2]
        for filename in ("nginx-domain-tls.conf", "nginx-ip.conf"):
            with self.subTest(vhost=filename):
                text = (repo / "deploy" / "hetzner" / filename).read_text(encoding="utf-8")
                self.assertEqual(text.count("include snippets/erp-pages.conf;"), 1)
                self.assertLess(text.index("location ~* ^/(?:AI-CONTEXT"), text.index("include snippets/erp-pages.conf;"))
                self.assertLess(text.index("include snippets/erp-pages.conf;"), text.index("location ~* \\.(?:gif"))
                self.assertIn("rewrite ^/projetos/?$ /projetos_publico.php last;", text)
                self.assertIn("location = /layout-base.html", text)
                self.assertIn("location / {\n        try_files $uri $uri/ /login.html;", text)
                self.assertNotRegex(text, r"rewrite\s+\^/dashboard/\?\$.*layout-base")

    def test_embedded_spa_submenus_use_clean_routes_and_data_page(self) -> None:
        repo = Path(__file__).resolve().parents[2]

        class PageLinks(HTMLParser):
            def __init__(self) -> None:
                super().__init__()
                self.links: list[tuple[str | None, str | None]] = []

            def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
                if tag != "a":
                    return
                data = dict(attrs)
                self.links.append((data.get("href"), data.get("data-page")))

        expected = {
            "protocolo.html", "relatorios_inventario.html", "marketplace_admin.html"
        }
        for filename in expected:
            with self.subTest(page=filename):
                parser = PageLinks()
                parser.feed((repo / "frontend/pages" / filename).read_text(encoding="utf-8"))
                self.assertTrue(parser.links)
                for href, page in parser.links:
                    self.assertIsNotNone(page)
                    self.assertEqual(href, f"/{page}")


if __name__ == "__main__":
    unittest.main(verbosity=2)
