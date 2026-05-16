<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap">
	<h1>Skillsaw Settings</h1>

	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'skillsaw_settings' ); ?>
		<input type="hidden" name="action" value="skillsaw_save_settings">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="anthropic_key">Anthropic API Key</label>
				</th>
				<td>
					<input
						type="password"
						id="anthropic_key"
						name="anthropic_key"
						value="<?php echo Skillsaw_Settings::get_anthropic_key() ? '••••••••' : ''; ?>"
						class="regular-text"
						autocomplete="off"
						placeholder="<?php echo Skillsaw_Settings::get_anthropic_key() ? '' : 'sk-ant-…'; ?>"
					>
					<p class="description">From Automattic's Anthropic account. Used for all Claude API calls. Leave unchanged to keep the existing key.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="greenhouse_key">Greenhouse Harvest API Key</label>
				</th>
				<td>
					<input
						type="password"
						id="greenhouse_key"
						name="greenhouse_key"
						value="<?php echo Skillsaw_Settings::get_greenhouse_key() ? '••••••••' : ''; ?>"
						class="regular-text"
						autocomplete="off"
						placeholder="<?php echo Skillsaw_Settings::get_greenhouse_key() ? '' : 'Enter key…'; ?>"
					>
					<p class="description">Harvest API key for pushing candidate transcripts and ratings to Greenhouse. Leave unchanged to keep the existing key.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="greenhouse_board_token">Greenhouse Board Token</label>
				</th>
				<td>
					<input
						type="text"
						id="greenhouse_board_token"
						name="greenhouse_board_token"
						value="<?php echo esc_attr( Skillsaw_Settings::get_greenhouse_board_token() ); ?>"
						class="regular-text"
					>
					<p class="description">Your Greenhouse job board token (e.g. <code>automattic</code>). Found in Greenhouse under Dev Center.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="greenhouse_user_id">Greenhouse User ID</label>
				</th>
				<td>
					<input
						type="text"
						id="greenhouse_user_id"
						name="greenhouse_user_id"
						value="<?php echo esc_attr( Skillsaw_Settings::get_greenhouse_user_id() ); ?>"
						class="regular-text"
					>
					<p class="description">Greenhouse user ID to attribute activity notes to (numeric). Found in your Greenhouse profile URL.</p>
				</td>
			</tr>
		</table>

		<?php submit_button( 'Save Settings' ); ?>
	</form>
</div>
