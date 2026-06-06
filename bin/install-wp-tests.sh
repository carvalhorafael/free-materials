#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
WP_TESTS_DIR=${WP_TESTS_DIR-${TMPDIR}/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress}

download() {
	local url=$1
	local output=$2

	if command -v curl >/dev/null 2>&1; then
		curl -sSL "$url" -o "$output"
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$output" "$url"
	else
		echo "curl or wget is required to download WordPress test files." >&2
		exit 1
	fi
}

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	local archive="${TMPDIR}/wordpress.tar.gz"
	if [ "$WP_VERSION" = "latest" ]; then
		download "https://wordpress.org/latest.tar.gz" "$archive"
	else
		download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$archive"
	fi

	tar --strip-components=1 -zxmf "$archive" -C "$WP_CORE_DIR"
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR/includes" ]; then
		return
	fi

	mkdir -p "$WP_TESTS_DIR"

	local includes_dir="${WP_TESTS_DIR}/includes"
	local data_dir="${WP_TESTS_DIR}/data"

	local svn_url="https://develop.svn.wordpress.org"
	if [ "$WP_VERSION" = "latest" ]; then
		svn_url="${svn_url}/trunk"
	else
		svn_url="${svn_url}/tags/${WP_VERSION}"
	fi

	if command -v svn >/dev/null 2>&1; then
		svn export --force --quiet "${svn_url}/tests/phpunit/includes/" "$includes_dir"
		svn export --force --quiet "${svn_url}/tests/phpunit/data/" "$data_dir"
	else
		echo "svn is required to install the WordPress PHPUnit test suite." >&2
		exit 1
	fi
}

install_config() {
	local sample="${WP_TESTS_DIR}/wp-tests-config-sample.php"
	if [ ! -f "$sample" ]; then
		download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$sample"
	fi

	local config="${WP_TESTS_DIR}/wp-tests-config.php"
	cp "$sample" "$config"

	sed -i.bak "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$config"
	sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "$config"
	sed -i.bak "s/yourusernamehere/${DB_USER}/" "$config"
	sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "$config"
	sed -i.bak "s|localhost|${DB_HOST}|" "$config"
	rm -f "${config}.bak"
}

create_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_wp
install_test_suite
install_config
create_db

echo "WordPress test suite installed in ${WP_TESTS_DIR}."
