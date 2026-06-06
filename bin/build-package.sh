#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="free-materials"
MAIN_FILE="${ROOT_DIR}/${PLUGIN_SLUG}.php"

VERSION="$(sed -nE 's/^[[:space:]*]*Version:[[:space:]]*([^[:space:]].*)$/\1/ip' "${MAIN_FILE}" | head -n 1 | tr -d '\r')"

if [[ -z "${VERSION}" ]]; then
  echo "Could not detect plugin version from ${MAIN_FILE}." >&2
  exit 1
fi

BUILD_DIR="${TMPDIR:-/tmp}/${PLUGIN_SLUG}-package"
PACKAGE_ROOT="${BUILD_DIR}/${PLUGIN_SLUG}"
DIST_DIR="${ROOT_DIR}/dist"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${PACKAGE_ROOT}" "${DIST_DIR}"

cp -R "${ROOT_DIR}/." "${PACKAGE_ROOT}/"

rm -rf \
  "${PACKAGE_ROOT}/.git" \
  "${PACKAGE_ROOT}/.github" \
  "${PACKAGE_ROOT}/.gitignore" \
  "${PACKAGE_ROOT}/.DS_Store" \
  "${PACKAGE_ROOT}/.env" \
  "${PACKAGE_ROOT}/.npmrc" \
  "${PACKAGE_ROOT}/bin" \
  "${PACKAGE_ROOT}/build" \
  "${PACKAGE_ROOT}/coverage" \
  "${PACKAGE_ROOT}/dist" \
  "${PACKAGE_ROOT}/node_modules" \
  "${PACKAGE_ROOT}/tests" \
  "${PACKAGE_ROOT}/vendor" \
  "${PACKAGE_ROOT}/AGENTS.md" \
  "${PACKAGE_ROOT}/.phpunit.cache" \
  "${PACKAGE_ROOT}/.phpunit.result.cache" \
  "${PACKAGE_ROOT}/phpunit.xml" \
  "${PACKAGE_ROOT}/phpunit.xml.dist" \
  "${PACKAGE_ROOT}/phpunit-unit.xml.dist"

find "${PACKAGE_ROOT}" -type f \( \
  -name "*.log" -o \
  -name "*.sql" -o \
  -name "*.sqlite" -o \
  -name "*.sqlite3" -o \
  -name "*.dump" -o \
  -name "*.bak" -o \
  -name "*.backup" \
\) -delete

rm -f "${ZIP_FILE}"
php -r '
$source = $argv[1];
$zipPath = $argv[2];
$zip = new ZipArchive();
if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    fwrite(STDERR, "Failed to create ZIP: {$zipPath}\n");
    exit(1);
}
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($files as $file) {
    $path = $file->getPathname();
    $relative = substr($path, strlen(dirname($source)) + 1);
    if ($file->isDir()) {
        $zip->addEmptyDir($relative);
        continue;
    }
    $zip->addFile($path, $relative);
}
$zip->close();
' "${PACKAGE_ROOT}" "${ZIP_FILE}"

rm -rf "${BUILD_DIR}"

echo "${ZIP_FILE}"
