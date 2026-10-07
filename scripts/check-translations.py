#!/usr/bin/env python3
"""Validate complete PO catalogs and their shipped MO files using GNU gettext."""
import argparse
import ast
import gettext
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = "wc-invoice-printer"
LOCALES = {
    "en_US": 2, "fa_IR": 2, "tr_TR": 2, "ar": 6, "fr_FR": 2, "de_DE": 2,
    "ru_RU": 3, "es_ES": 2, "pt_PT": 2, "pt_BR": 2, "hy": 2, "hi_IN": 2,
    "zh_CN": 1, "ja": 1,
}


def read_po(path):
    entries = {}
    entry = {}
    field = None
    fuzzy = False

    def finish():
        if "msgid" not in entry:
            return
        key = (entry.get("msgctxt", ""), entry["msgid"])
        if key in entries:
            raise ValueError("Duplicate message in " + str(path) + ": " + repr(key))
        entries[key] = (dict(entry), fuzzy)

    for line in path.read_text(encoding="utf-8").splitlines() + [""]:
        if not line.strip():
            finish()
            entry, field, fuzzy = {}, None, False
        elif line.startswith("#, "):
            fuzzy |= "fuzzy" in line[3:].split(", ")
        elif line.startswith("#"):
            continue
        elif line.startswith('"'):
            entry[field] += ast.literal_eval(line)
        else:
            field, value = line.split(" ", 1)
            entry[field] = ast.literal_eval(value)
    return entries


def check(args):
    compiler = shutil.which("msgfmt")
    if not compiler:
        raise ValueError("GNU gettext is required: install gettext so msgfmt is available.")
    source = read_po(args.pot)
    source.pop(("", ""))
    counts = (0, 1, 2, 3, 4, 5, 10, 11, 21, 22, 99, 100, 101, 102, 111)
    with tempfile.TemporaryDirectory(prefix="wcip-i18n-") as temporary:
        for locale, plural_count in LOCALES.items():
            po = ROOT / "languages" / (DOMAIN + "-" + locale + ".po")
            catalog = read_po(po)
            header = catalog.pop(("", ""))[0]["msgstr"]
            if "Language: " + locale + "\n" not in header or "X-Domain: " + DOMAIN + "\n" not in header:
                raise ValueError(str(po) + ": incorrect language or domain header")
            if set(catalog) != set(source):
                raise ValueError(str(po) + ": messages differ from POT; missing=" + repr(set(source) - set(catalog)) + "; extra=" + repr(set(catalog) - set(source)))
            for key, (entry, fuzzy) in catalog.items():
                if fuzzy or entry.get("msgid_plural") != source[key][0].get("msgid_plural"):
                    raise ValueError(str(po) + ": fuzzy or inconsistent message " + repr(key))
                translations = [value for field, value in entry.items() if field.startswith("msgstr")]
                expected = plural_count if "msgid_plural" in entry else 1
                if len(translations) != expected or not all(translations):
                    raise ValueError(str(po) + ": incomplete translation " + repr(key))
            compiled = Path(temporary) / (locale + ".mo")
            subprocess.run([compiler, "--check", "--check-format", "-o", str(compiled), str(po)], check=True)
            with compiled.open("rb") as stream:
                translations = gettext.GNUTranslations(stream)
            # Exercise each language's real plural rule, including Arabic and Russian.
            if int(re.search(r"nplurals=(\d+)", translations.info()["plural-forms"])[1]) != plural_count:
                raise ValueError(str(po) + ": incorrect plural count")
            for key, (entry, _) in catalog.items():
                original = entry["msgid"]
                context = entry.get("msgctxt")
                if "msgid_plural" in entry:
                    for count in counts:
                        result = translations.npgettext(context, original, entry["msgid_plural"], count) if context else translations.ngettext(original, entry["msgid_plural"], count)
                        if result != entry["msgstr[" + str(translations.plural(count)) + "]"]:
                            raise ValueError(str(po) + ": plural lookup failed")
                else:
                    result = translations.pgettext(context, original) if context else translations.gettext(original)
                    if result != entry["msgstr"]:
                        raise ValueError(str(po) + ": compiled lookup failed")
            shipped = po.with_suffix(".mo")
            if args.compile:
                shipped.write_bytes(compiled.read_bytes())
            elif not shipped.exists() or shipped.read_bytes() != compiled.read_bytes():
                raise ValueError(str(shipped) + ": missing or stale; run composer i18n:build")
            print(locale + ": " + str(len(catalog)) + " messages; " + str(plural_count) + " plural forms; OK")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pot", type=Path, default=ROOT / "languages" / (DOMAIN + ".pot"))
    parser.add_argument("--compile", action="store_true", help="rebuild the shipped MO files after validation")
    try:
        check(parser.parse_args())
    except (ValueError, KeyError, SyntaxError, OSError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
