<?php
/**
 * Admin screen that walks an editor through a CSV import.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_Import_Admin_Page {
	public const PAGE_SLUG       = 'free-materials-import';
	public const CAPABILITY      = 'manage_options';
	public const DOWNLOAD_ACTION = 'free_materials_download_import_template';
	public const UPLOAD_ACTION   = 'free_materials_validate_import_file';
	public const IMPORT_ACTION   = 'free_materials_execute_import';
	public const CLEAR_ACTION    = 'free_materials_clear_import_file';
	public const NONCE_ACTION    = 'free_materials_import_admin';
	public const CLEANUP_HOOK    = 'free_materials_cleanup_import_file';
	public const TEMP_PREFIX     = 'free-materials-import-';
	public const MAX_FILE_SIZE   = 5242880;
	public const SESSION_TTL     = HOUR_IN_SECONDS;

	private Free_Materials_CSV_Parser $csv_parser;

	private Free_Materials_Importer $importer;

	private string $page_hook = '';

	public function __construct( Free_Materials_CSV_Parser $csv_parser, Free_Materials_Importer $importer ) {
		$this->csv_parser = $csv_parser;
		$this->importer   = $importer;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'download_template' ) );
		add_action( 'admin_post_' . self::UPLOAD_ACTION, array( $this, 'validate_upload' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'execute_import' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'clear_upload' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_temp_file' ) );
	}

	public function register_admin_menu(): void {
		$this->page_hook = (string) add_submenu_page(
			'edit.php?post_type=' . Free_Materials_Content_Domain::POST_TYPE,
			__( 'Importar materiais', 'free-materials' ),
			__( 'Importar', 'free-materials' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $this->page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'free-materials-import-admin',
			plugins_url( 'assets/admin-import.css', FREE_MATERIALS_FILE ),
			array(),
			FREE_MATERIALS_VERSION
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para importar materiais.', 'free-materials' ) );
		}

		$download_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION ),
			self::NONCE_ACTION
		);
		$session      = $this->get_import_session();
		$has_report   = $session && isset( $session['report'] ) && is_array( $session['report'] );
		?>
		<div class="wrap free-materials-import-admin">
			<h1><?php esc_html_e( 'Importar materiais', 'free-materials' ); ?></h1>
			<p class="free-materials-import-admin__intro">
				<?php esc_html_e( 'Envie vários materiais em um arquivo CSV padronizado. Antes de qualquer importação, o plugin valida a planilha e mostra o que será criado.', 'free-materials' ); ?>
			</p>

			<?php $this->render_notice(); ?>

			<ol class="free-materials-import-steps" aria-label="<?php esc_attr_e( 'Etapas da importação', 'free-materials' ); ?>">
				<li class="free-materials-import-steps__item<?php echo esc_attr( $session ? '' : ' is-current' ); ?>"<?php if ( ! $session ) : ?> aria-current="step"<?php endif; ?>>
					<span class="free-materials-import-steps__number">1</span>
					<span><?php esc_html_e( 'Enviar arquivo', 'free-materials' ); ?></span>
				</li>
				<li class="free-materials-import-steps__item<?php echo esc_attr( $session && ! $has_report ? ' is-current' : '' ); ?>"<?php if ( $session && ! $has_report ) : ?> aria-current="step"<?php endif; ?>>
					<span class="free-materials-import-steps__number">2</span>
					<span><?php esc_html_e( 'Revisar dados', 'free-materials' ); ?></span>
				</li>
				<li class="free-materials-import-steps__item<?php echo esc_attr( $has_report ? ' is-current' : '' ); ?>"<?php if ( $has_report ) : ?> aria-current="step"<?php endif; ?>>
					<span class="free-materials-import-steps__number">3</span>
					<span><?php esc_html_e( 'Importar', 'free-materials' ); ?></span>
				</li>
			</ol>

			<?php if ( $has_report ) : ?>
				<?php $this->render_import_report( $session ); ?>
			<?php elseif ( $session ) : ?>
				<?php $this->render_review( $session ); ?>
			<?php else : ?>
				<div class="free-materials-import-admin__layout">
					<main class="free-materials-import-admin__main">
						<section class="free-materials-import-section" aria-labelledby="free-materials-import-prepare-title">
							<h2 id="free-materials-import-prepare-title"><?php esc_html_e( 'Prepare sua planilha', 'free-materials' ); ?></h2>
							<p><?php esc_html_e( 'Use o modelo para garantir que os nomes e a ordem das colunas sejam reconhecidos pelo plugin.', 'free-materials' ); ?></p>
							<a class="button button-secondary" href="<?php echo esc_url( $download_url ); ?>">
								<span class="dashicons dashicons-download" aria-hidden="true"></span>
								<?php esc_html_e( 'Baixar modelo CSV', 'free-materials' ); ?>
							</a>
						</section>

						<section class="free-materials-import-section" aria-labelledby="free-materials-import-upload-title">
							<h2 id="free-materials-import-upload-title"><?php esc_html_e( 'Envie o arquivo', 'free-materials' ); ?></h2>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" method="post">
								<input name="action" type="hidden" value="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>">
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>

								<div class="free-materials-import-upload">
									<label for="free-materials-import-file">
										<strong><?php esc_html_e( 'Selecione uma planilha CSV', 'free-materials' ); ?></strong>
										<span><?php esc_html_e( 'Arquivo de até 5 MB, separado por vírgulas ou ponto e vírgula.', 'free-materials' ); ?></span>
									</label>
									<input accept=".csv,text/csv" id="free-materials-import-file" name="free_materials_import_file" required type="file">
								</div>

								<p class="submit">
									<button class="button button-primary button-hero" type="submit">
										<?php esc_html_e( 'Validar planilha', 'free-materials' ); ?>
									</button>
								</p>
								<p class="description">
									<?php esc_html_e( 'Esta etapa verifica o formato do arquivo. Nenhum material será criado.', 'free-materials' ); ?>
								</p>
							</form>
						</section>
					</main>

					<aside class="free-materials-import-admin__aside" aria-labelledby="free-materials-import-guidance-title">
						<h2 id="free-materials-import-guidance-title"><?php esc_html_e( 'Antes de enviar', 'free-materials' ); ?></h2>
						<ul>
							<li><?php esc_html_e( 'Não altere os cabeçalhos do modelo.', 'free-materials' ); ?></li>
							<li><?php esc_html_e( 'Use um identificador externo único para evitar duplicações.', 'free-materials' ); ?></li>
							<li><?php esc_html_e( 'Separe categorias e destaques com a barra vertical.', 'free-materials' ); ?></li>
							<li><?php esc_html_e( 'Informe apenas URLs públicas para capas e entrega.', 'free-materials' ); ?></li>
						</ul>
						<p>
							<strong><?php esc_html_e( 'Campos obrigatórios:', 'free-materials' ); ?></strong><br>
							<code>id_externo</code>, <code>titulo</code>
						</p>
						<p>
							<strong><?php esc_html_e( 'Formatos aceitos:', 'free-materials' ); ?></strong><br>
							<code><?php echo esc_html( implode( ', ', array_keys( Free_Materials_Content_Domain::formats() ) ) ); ?></code>
						</p>
					</aside>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function download_template(): void {
		$this->authorize_request();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="modelo-importacao-materiais.csv"' );

		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Não foi possível gerar o modelo CSV.', 'free-materials' ) );
		}

		// Without the BOM, Excel reads the accents as mojibake.
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, Free_Materials_CSV_Parser::headers(), ',', '"', '' );
		fputcsv( $output, $this->template_example_row(), ',', '"', '' );
		fclose( $output );
		exit;
	}

	public function validate_upload(): void {
		$this->authorize_request();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every member is validated in validate_csv_file().
		$file = isset( $_FILES['free_materials_import_file'] ) && is_array( $_FILES['free_materials_import_file'] )
			? $_FILES['free_materials_import_file']
			: array();

		$notice = $this->validate_csv_file( $file );
		if ( 'file_ready' !== $notice ) {
			$this->redirect_with_notice( $notice );
		}

		$tmp_name    = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$stored_path = wp_tempnam( self::TEMP_PREFIX . get_current_user_id() . '.csv' );
		if ( ! $stored_path || ! move_uploaded_file( $tmp_name, $stored_path ) ) {
			$this->redirect_with_notice( 'store_error' );
		}

		$analysis = $this->csv_parser->analyze( $stored_path );
		if ( is_wp_error( $analysis ) ) {
			$this->cleanup_temp_file( $stored_path );
			$this->redirect_with_notice( 'analysis_error' );
		}

		$this->delete_import_session();
		set_transient(
			$this->session_key(),
			array(
				'path'        => $stored_path,
				'file_name'   => isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'materiais.csv',
				'file_size'   => isset( $file['size'] ) ? (int) $file['size'] : 0,
				'analysis'    => $analysis,
				'uploaded_at' => time(),
			),
			self::SESSION_TTL
		);

		wp_schedule_single_event( time() + self::SESSION_TTL, self::CLEANUP_HOOK, array( $stored_path ) );
		$this->redirect_with_notice( 'analysis_ready' );
	}

	public function clear_upload(): void {
		$this->authorize_request();
		$this->delete_import_session();
		$this->redirect_with_notice( 'file_cleared' );
	}

	public function execute_import(): void {
		$this->authorize_request();

		$session = $this->get_import_session();
		if ( ! $session || ! isset( $session['path'] ) || ! is_string( $session['path'] ) ) {
			$this->redirect_with_notice( 'session_expired' );
		}

		$publication_mode = isset( $_POST['free_materials_publication_mode'] )
			? sanitize_key( wp_unslash( $_POST['free_materials_publication_mode'] ) )
			: 'draft';
		if ( ! in_array( $publication_mode, array( 'draft', 'publish', 'respect_csv' ), true ) ) {
			$publication_mode = 'draft';
		}

		$existing_mode = isset( $_POST['free_materials_existing_mode'] )
			? sanitize_key( wp_unslash( $_POST['free_materials_existing_mode'] ) )
			: 'skip';
		if ( ! in_array( $existing_mode, array( 'skip', 'update' ), true ) ) {
			$existing_mode = 'skip';
		}

		$report = $this->importer->import(
			$session['path'],
			isset( $session['file_name'] ) && is_string( $session['file_name'] ) ? $session['file_name'] : 'materiais.csv',
			array(
				'existing_mode'    => $existing_mode,
				'publication_mode' => $publication_mode,
			)
		);

		if ( is_wp_error( $report ) ) {
			$this->redirect_with_notice( 'import_error' );
		}

		$session['report']           = $report;
		$session['publication_mode'] = $publication_mode;
		$session['existing_mode']    = $existing_mode;
		set_transient( $this->session_key(), $session, self::SESSION_TTL );

		$this->redirect_with_notice( 'import_complete' );
	}

	/**
	 * Validate the file envelope and the canonical CSV header.
	 *
	 * @param array<string,mixed> $file Uploaded file data.
	 */
	public function validate_csv_file( array $file ): string {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			return 'upload_error';
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 ) {
			return 'empty_file';
		}

		if ( $size > self::MAX_FILE_SIZE ) {
			return 'file_too_large';
		}

		$name      = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$file_type = wp_check_filetype( $name, array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== ( $file_type['ext'] ?? '' ) ) {
			return 'invalid_file_type';
		}

		$tmp_name = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp_name || ! is_readable( $tmp_name ) ) {
			return 'unreadable_file';
		}

		$inspection = $this->csv_parser->inspect_header( $tmp_name );
		if ( is_wp_error( $inspection ) || ! Free_Materials_CSV_Parser::has_canonical_headers( $inspection['headers'] ) ) {
			return 'invalid_headers';
		}

		return 'file_ready';
	}

	/**
	 * @param array<string,mixed> $session Import session with a report.
	 */
	private function render_import_report( array $session ): void {
		$report    = isset( $session['report'] ) && is_array( $session['report'] ) ? $session['report'] : array();
		$summary   = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$rows      = isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array();
		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::CLEAR_ACTION ),
			self::NONCE_ACTION
		);
		?>
		<section class="free-materials-import-review" aria-labelledby="free-materials-import-report-title">
			<div class="free-materials-import-review__header">
				<div>
					<h2 id="free-materials-import-report-title"><?php esc_html_e( 'Importação concluída', 'free-materials' ); ?></h2>
					<p>
						<?php esc_html_e( 'Lote:', 'free-materials' ); ?>
						<code><?php echo esc_html( (string) ( $report['batch_id'] ?? '' ) ); ?></code>
					</p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Free_Materials_Content_Domain::POST_TYPE ) ); ?>">
					<?php esc_html_e( 'Ver materiais', 'free-materials' ); ?>
				</a>
			</div>

			<div class="free-materials-import-summary free-materials-import-summary--report" aria-label="<?php esc_attr_e( 'Resumo da importação', 'free-materials' ); ?>">
				<div>
					<strong><?php echo esc_html( (string) (int) ( $summary['total'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Registros', 'free-materials' ); ?></span>
				</div>
				<div class="is-success">
					<strong><?php echo esc_html( (string) (int) ( $summary['created'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Criados', 'free-materials' ); ?></span>
				</div>
				<div class="is-success">
					<strong><?php echo esc_html( (string) (int) ( $summary['updated'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Atualizados', 'free-materials' ); ?></span>
				</div>
				<div class="is-success">
					<strong><?php echo esc_html( (string) (int) ( $summary['media_imported'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Capas importadas', 'free-materials' ); ?></span>
				</div>
				<div class="is-error">
					<strong><?php echo esc_html( (string) (int) ( $summary['media_failed'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Capas com erro', 'free-materials' ); ?></span>
				</div>
				<div class="is-error">
					<strong><?php echo esc_html( (string) ( (int) ( $summary['failed'] ?? 0 ) + (int) ( $summary['invalid'] ?? 0 ) ) ); ?></strong>
					<span><?php esc_html_e( 'Não importados', 'free-materials' ); ?></span>
				</div>
			</div>

			<div class="notice notice-info inline free-materials-import-report-note">
				<p><?php esc_html_e( 'As capas válidas foram adicionadas à biblioteca e definidas como imagem destacada. Uma falha de capa não remove o material criado.', 'free-materials' ); ?></p>
			</div>

			<div class="free-materials-import-table-wrap">
				<table class="widefat striped free-materials-import-table free-materials-import-report-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Linha', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Identificador', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Material', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Resultado', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Capa', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Registro', 'free-materials' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php $this->render_report_row( is_array( $row ) ? $row : array() ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="free-materials-import-review__footer free-materials-import-review__footer--compact">
				<p><?php esc_html_e( 'Você pode revisar os materiais criados ou iniciar uma nova importação.', 'free-materials' ); ?></p>
				<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Importar outra planilha', 'free-materials' ); ?></a>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed> $row Import report row.
	 */
	private function render_report_row( array $row ): void {
		$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'failed';
		$labels = array(
			'created' => array( __( 'Criado', 'free-materials' ), 'is-ready' ),
			'updated' => array( __( 'Atualizado', 'free-materials' ), 'is-ready' ),
			'skipped' => array( __( 'Ignorado', 'free-materials' ), 'is-warning' ),
			'invalid' => array( __( 'Inválido', 'free-materials' ), 'is-error' ),
			'failed'  => array( __( 'Erro', 'free-materials' ), 'is-error' ),
		);

		$media_status = isset( $row['media_status'] ) ? sanitize_key( (string) $row['media_status'] ) : 'not_processed';
		$media_labels = array(
			'imported'      => array( __( 'Importada', 'free-materials' ), 'is-ready' ),
			'missing'       => array( __( 'Não informada', 'free-materials' ), 'is-warning' ),
			'failed'        => array( __( 'Erro', 'free-materials' ), 'is-error' ),
			'not_processed' => array( __( 'Não processada', 'free-materials' ), 'is-neutral' ),
		);

		$label          = $labels[ $status ] ?? $labels['failed'];
		$media_label    = $media_labels[ $media_status ] ?? $media_labels['not_processed'];
		$post_id        = (int) ( $row['post_id'] ?? 0 );
		$attachment_id  = (int) ( $row['attachment_id'] ?? 0 );
		$post_status    = isset( $row['post_status'] ) ? sanitize_key( (string) $row['post_status'] ) : '';
		$post_statuses  = array(
			'draft'   => __( 'Rascunho', 'free-materials' ),
			'pending' => __( 'Pendente', 'free-materials' ),
			'publish' => __( 'Publicado', 'free-materials' ),
		);
		$edit_url       = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
		$attachment_url = $attachment_id ? get_edit_post_link( $attachment_id, 'raw' ) : '';
		?>
		<tr>
			<td><?php echo esc_html( (string) (int) ( $row['line'] ?? 0 ) ); ?></td>
			<td><code><?php echo esc_html( $this->display_value( $row['external_id'] ?? '' ) ); ?></code></td>
			<td><strong><?php echo esc_html( $this->display_value( $row['title'] ?? '' ) ); ?></strong></td>
			<td>
				<span class="free-materials-import-status <?php echo esc_attr( $label[1] ); ?>"><?php echo esc_html( $label[0] ); ?></span>
				<?php if ( isset( $post_statuses[ $post_status ] ) ) : ?>
					<p class="description"><?php echo esc_html( $post_statuses[ $post_status ] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $row['message'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $row['message'] ); ?></p>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $attachment_url ) : ?>
					<a href="<?php echo esc_url( $attachment_url ); ?>"><span class="free-materials-import-status <?php echo esc_attr( $media_label[1] ); ?>"><?php echo esc_html( $media_label[0] ); ?></span></a>
				<?php else : ?>
					<span class="free-materials-import-status <?php echo esc_attr( $media_label[1] ); ?>"><?php echo esc_html( $media_label[0] ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $edit_url ) : ?>
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Editar material', 'free-materials' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Não criado', 'free-materials' ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string,mixed> $session Import review session.
	 */
	private function render_review( array $session ): void {
		$analysis    = isset( $session['analysis'] ) && is_array( $session['analysis'] ) ? $session['analysis'] : array();
		$summary     = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
		$rows        = isset( $analysis['rows'] ) && is_array( $analysis['rows'] ) ? $analysis['rows'] : array();
		$errors      = isset( $analysis['file_errors'] ) && is_array( $analysis['file_errors'] ) ? $analysis['file_errors'] : array();
		$valid_count = (int) ( $summary['valid'] ?? 0 );
		$can_import  = $valid_count > 0 && ! $errors;
		$clear_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::CLEAR_ACTION ),
			self::NONCE_ACTION
		);
		?>
		<section class="free-materials-import-review" aria-labelledby="free-materials-import-review-title">
			<div class="free-materials-import-review__header">
				<div>
					<h2 id="free-materials-import-review-title"><?php esc_html_e( 'Revisão da planilha', 'free-materials' ); ?></h2>
					<p>
						<strong><?php echo esc_html( (string) ( $session['file_name'] ?? 'materiais.csv' ) ); ?></strong>
						<span aria-hidden="true"> · </span>
						<?php echo esc_html( size_format( (int) ( $session['file_size'] ?? 0 ) ) ); ?>
					</p>
				</div>
				<a class="button button-secondary" href="<?php echo esc_url( $clear_url ); ?>">
					<?php esc_html_e( 'Trocar arquivo', 'free-materials' ); ?>
				</a>
			</div>

			<div class="free-materials-import-summary" aria-label="<?php esc_attr_e( 'Resumo da validação', 'free-materials' ); ?>">
				<div>
					<strong><?php echo esc_html( (string) (int) ( $summary['total'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Registros', 'free-materials' ); ?></span>
				</div>
				<div class="is-success">
					<strong><?php echo esc_html( (string) $valid_count ); ?></strong>
					<span><?php esc_html_e( 'Válidos', 'free-materials' ); ?></span>
				</div>
				<div class="is-warning">
					<strong><?php echo esc_html( (string) (int) ( $summary['with_warning'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Com avisos', 'free-materials' ); ?></span>
				</div>
				<div class="is-error">
					<strong><?php echo esc_html( (string) (int) ( $summary['invalid'] ?? 0 ) ); ?></strong>
					<span><?php esc_html_e( 'Com erros', 'free-materials' ); ?></span>
				</div>
			</div>

			<?php if ( $errors ) : ?>
				<div class="notice notice-error inline">
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( (string) $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="free-materials-import-table-wrap">
				<table class="widefat striped free-materials-import-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Linha', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Identificador', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Material', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ficha', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Capa', 'free-materials' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Resultado', 'free-materials' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $rows ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'Nenhum registro encontrado.', 'free-materials' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $this->render_review_row( is_array( $row ) ? $row : array() ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( (int) ( $summary['total'] ?? 0 ) > count( $rows ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %d: number of preview rows. */
						esc_html__( 'A prévia mostra os primeiros %d registros. Todos foram considerados no resumo.', 'free-materials' ),
						count( $rows )
					);
					?>
				</p>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="free-materials-import-review__footer" method="post">
				<input name="action" type="hidden" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<fieldset class="free-materials-import-mode">
					<legend><strong><?php esc_html_e( 'Como os materiais devem entrar?', 'free-materials' ); ?></strong></legend>
					<label>
						<input checked name="free_materials_publication_mode" type="radio" value="draft">
						<span>
							<strong><?php esc_html_e( 'Importar todos como rascunho', 'free-materials' ); ?></strong>
							<small><?php esc_html_e( 'Opção recomendada para revisar conteúdo e capas antes da publicação.', 'free-materials' ); ?></small>
						</span>
					</label>
					<label>
						<input name="free_materials_publication_mode" type="radio" value="publish">
						<span>
							<strong><?php esc_html_e( 'Publicar automaticamente os materiais aptos', 'free-materials' ); ?></strong>
							<small><?php esc_html_e( 'Um material sem url_entrega continua como rascunho: o formulário não teria o que entregar.', 'free-materials' ); ?></small>
						</span>
					</label>
					<label>
						<input name="free_materials_publication_mode" type="radio" value="respect_csv">
						<span>
							<strong><?php esc_html_e( 'Respeitar status_publicacao da planilha', 'free-materials' ); ?></strong>
							<small><?php esc_html_e( 'Use somente quando os status já tiverem sido revisados na planilha.', 'free-materials' ); ?></small>
						</span>
					</label>
				</fieldset>

				<fieldset class="free-materials-import-mode">
					<legend><strong><?php esc_html_e( 'E quando o id_externo já existir?', 'free-materials' ); ?></strong></legend>
					<label>
						<input checked name="free_materials_existing_mode" type="radio" value="skip">
						<span>
							<strong><?php esc_html_e( 'Ignorar o material existente', 'free-materials' ); ?></strong>
							<small><?php esc_html_e( 'Nada é sobrescrito. Reimportar a mesma planilha não altera nem duplica nada.', 'free-materials' ); ?></small>
						</span>
					</label>
					<label>
						<input name="free_materials_existing_mode" type="radio" value="update">
						<span>
							<strong><?php esc_html_e( 'Atualizar com os dados da planilha', 'free-materials' ); ?></strong>
							<small><?php esc_html_e( 'Título, resumo, conteúdo, ficha e categorias passam a valer os da planilha. Edições feitas no editor nesses campos são perdidas.', 'free-materials' ); ?></small>
						</span>
					</label>
				</fieldset>

				<div class="free-materials-import-review__actions">
					<p class="description"><?php esc_html_e( 'Linhas com erro serão ignoradas. As capas válidas serão baixadas para a biblioteca e definidas como imagem destacada.', 'free-materials' ); ?></p>
					<div>
						<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Voltar e trocar arquivo', 'free-materials' ); ?></a>
						<button class="button button-primary"<?php disabled( ! $can_import ); ?> type="submit">
							<?php
							printf(
								/* translators: %d: number of valid materials. */
								esc_html( _n( 'Importar %d material', 'Importar %d materiais', $valid_count, 'free-materials' ) ),
								$valid_count
							);
							?>
						</button>
					</div>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed> $row Analyzed CSV row.
	 */
	private function render_review_row( array $row ): void {
		$data     = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array();
		$errors   = isset( $row['errors'] ) && is_array( $row['errors'] ) ? $row['errors'] : array();
		$warnings = isset( $row['warnings'] ) && is_array( $row['warnings'] ) ? $row['warnings'] : array();

		$status_class = 'is-ready';
		$status_label = __( 'Pronto', 'free-materials' );
		if ( $errors ) {
			$status_class = 'is-error';
			$status_label = __( 'Erro', 'free-materials' );
		} elseif ( $warnings ) {
			$status_class = 'is-warning';
			$status_label = __( 'Atenção', 'free-materials' );
		}

		$specs = array_filter(
			array(
				Free_Materials_Content_Domain::formats()[ mb_strtolower( $data['formato'] ?? '' ) ] ?? '',
				$data['paginas'] ?? '',
				$data['tamanho_arquivo'] ?? '',
			)
		);
		?>
		<tr>
			<td><?php echo esc_html( (string) (int) ( $row['line'] ?? 0 ) ); ?></td>
			<td><code><?php echo esc_html( $this->display_value( $data['id_externo'] ?? '' ) ); ?></code></td>
			<td>
				<strong><?php echo esc_html( $this->display_value( $data['titulo'] ?? '' ) ); ?></strong><br>
				<span class="description"><?php echo esc_html( $this->display_value( $data['categorias'] ?? '' ) ); ?></span>
			</td>
			<td><?php echo esc_html( $specs ? implode( ' · ', $specs ) : $this->display_value( '' ) ); ?></td>
			<td><?php echo esc_html( '' !== ( $data['imagem_url'] ?? '' ) ? __( 'Informada', 'free-materials' ) : __( 'Não informada', 'free-materials' ) ); ?></td>
			<td>
				<span class="free-materials-import-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
				<?php if ( $errors || $warnings ) : ?>
					<ul class="free-materials-import-row-messages">
						<?php foreach ( $errors as $error ) : ?>
							<li class="is-error"><?php echo esc_html( (string) $error ); ?></li>
						<?php endforeach; ?>
						<?php foreach ( $warnings as $warning ) : ?>
							<li class="is-warning"><?php echo esc_html( (string) $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A filled row in the template, so the separators are shown rather than
	 * explained.
	 *
	 * @return string[]
	 */
	private function template_example_row(): array {
		return array(
			'mat-001',
			__( 'Checklist de revisão para o ENEM', 'free-materials' ),
			__( 'Uma lista prática para revisar conteúdos prioritários sem perder ritmo.', 'free-materials' ),
			__( 'Use este checklist para organizar sua revisão semanal.', 'free-materials' ),
			__( 'Redação|Simulados', 'free-materials' ),
			'checklist',
			'12',
			'1,8 MB',
			__( 'Intermediário', 'free-materials' ),
			__( 'Rotina semanal|Onde você perdeu pontos|Plano da semana seguinte', 'free-materials' ),
			'nao',
			'',
			'https://exemplo.com/entrega/checklist.pdf',
			'',
			__( 'Baixar checklist', 'free-materials' ),
			'https://exemplo.com/capas/checklist.jpg',
			'draft',
		);
	}

	private function display_value( mixed $value ): string {
		$value = trim( (string) $value );

		return '' === $value ? __( 'Não informado', 'free-materials' ) : $value;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function get_import_session(): ?array {
		$session = get_transient( $this->session_key() );
		if ( ! is_array( $session ) ) {
			return null;
		}

		$path = isset( $session['path'] ) && is_string( $session['path'] ) ? $session['path'] : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			delete_transient( $this->session_key() );

			return null;
		}

		return $session;
	}

	private function delete_import_session(): void {
		$session = get_transient( $this->session_key() );
		if ( is_array( $session ) && isset( $session['path'] ) && is_string( $session['path'] ) ) {
			$this->cleanup_temp_file( $session['path'] );
		}

		delete_transient( $this->session_key() );
	}

	/**
	 * Only ever deletes a file this class created, in the temp directory.
	 */
	public function cleanup_temp_file( string $path ): void {
		$normalized_path = wp_normalize_path( $path );
		$temp_directory  = trailingslashit( wp_normalize_path( get_temp_dir() ) );
		$file_name       = wp_basename( $normalized_path );

		if ( ! str_starts_with( $normalized_path, $temp_directory ) || ! str_starts_with( $file_name, self::TEMP_PREFIX ) ) {
			return;
		}

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	private function session_key(): string {
		return 'free_materials_import_session_' . get_current_user_id();
	}

	private function authorize_request(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para importar materiais.', 'free-materials' ) );
		}

		check_admin_referer( self::NONCE_ACTION );
	}

	private function redirect_with_notice( string $notice ): void {
		$url = add_query_arg(
			array(
				'post_type'                   => Free_Materials_Content_Domain::POST_TYPE,
				'page'                        => self::PAGE_SLUG,
				'free_materials_import_notice' => $notice,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a redirect flag that only picks a message.
		$notice = isset( $_GET['free_materials_import_notice'] )
			? sanitize_key( wp_unslash( $_GET['free_materials_import_notice'] ) )
			: '';

		$notices = array(
			'analysis_ready'    => array( 'success', __( 'Planilha analisada. Revise os dados e as mensagens antes de continuar.', 'free-materials' ) ),
			'import_complete'   => array( 'success', __( 'Importação concluída. Confira abaixo o resultado de cada registro.', 'free-materials' ) ),
			'file_cleared'      => array( 'success', __( 'A planilha temporária foi descartada. Você pode enviar outro arquivo.', 'free-materials' ) ),
			'session_expired'   => array( 'error', __( 'A planilha temporária expirou. Envie o arquivo novamente.', 'free-materials' ) ),
			'import_error'      => array( 'error', __( 'Não foi possível concluir a importação. Revise a planilha e tente novamente.', 'free-materials' ) ),
			'upload_error'      => array( 'error', __( 'Não foi possível receber o arquivo. Selecione a planilha e tente novamente.', 'free-materials' ) ),
			'empty_file'        => array( 'error', __( 'O arquivo enviado está vazio.', 'free-materials' ) ),
			'file_too_large'    => array( 'error', __( 'O arquivo excede o limite de 5 MB.', 'free-materials' ) ),
			'invalid_file_type' => array( 'error', __( 'Envie um arquivo com extensão CSV.', 'free-materials' ) ),
			'unreadable_file'   => array( 'error', __( 'O arquivo não pôde ser lido pelo WordPress.', 'free-materials' ) ),
			'invalid_headers'   => array( 'error', __( 'As colunas não correspondem ao modelo do plugin. Baixe o modelo e preserve os cabeçalhos.', 'free-materials' ) ),
			'store_error'       => array( 'error', __( 'Não foi possível armazenar temporariamente a planilha.', 'free-materials' ) ),
			'analysis_error'    => array( 'error', __( 'Não foi possível analisar o conteúdo da planilha.', 'free-materials' ) ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $notices[ $notice ][1] ); ?></p>
		</div>
		<?php
	}
}
