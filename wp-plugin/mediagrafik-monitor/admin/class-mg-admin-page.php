<?php

// Zabránění přímému přístupu k souboru
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nastavení → MEDIAGRAFIK Monitor: vložení API klíče, adresa hubu,
 * povolení přihlášení ze Správy webů, kdy hub naposledy četl data,
 * a tlačítko „Otestovat sběr dat".
 *
 * Každá akce má nonce a kontrolu `manage_options`.
 */
final class MG_Admin_Page
{
    const SLUG = 'mediagrafik-monitor';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
    }

    public function add_menu()
    {
        add_options_page('MEDIAGRAFIK Monitor', 'MEDIAGRAFIK Monitor', 'manage_options', self::SLUG, array($this, 'render'));
    }

    public function handle_actions()
    {
        if (!isset($_POST['mg_monitor_action']) || !current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('mg_monitor_settings');

        $action = sanitize_key((string) $_POST['mg_monitor_action']);

        if ($action === 'save') {
            $key = isset($_POST['mg_monitor_key']) ? trim(wp_unslash((string) $_POST['mg_monitor_key'])) : '';
            $hub = isset($_POST['mg_monitor_hub']) ? esc_url_raw(trim(wp_unslash((string) $_POST['mg_monitor_hub']))) : '';

            if ($key !== '' && !MG_Api_Key::looks_valid($key)) {
                add_settings_error(self::SLUG, 'key', 'Klíč nemá očekávaný tvar (mg_live_ + 32 znaků). Zkopírujte ho ze Správy webů znovu.', 'error');

                return;
            }

            if ($key !== '') {
                MG_Api_Key::store($key);
            }

            update_option(MG_Monitor::OPTION_HUB_URL, $hub, false);
            update_option(MG_Monitor::OPTION_ALLOW_LOGIN, isset($_POST['mg_monitor_allow_login']) ? '1' : '0', false);
            add_settings_error(self::SLUG, 'saved', $key !== '' ? 'Klíč je uložený. Ve Správě webů klikněte na „Zkontrolovat teď".' : 'Nastavení uloženo.', 'updated');
        }

        if ($action === 'disconnect') {
            MG_Api_Key::store('');
            add_settings_error(self::SLUG, 'disconnected', 'Klíč je smazaný — hub se k webu už nedostane.', 'updated');
        }
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $hint = MG_Api_Key::hint();
        $last_seen = (string) get_option(MG_Monitor::OPTION_LAST_SEEN, '');
        $hub = (string) get_option(MG_Monitor::OPTION_HUB_URL, '');
        $test = null;

        if (isset($_GET['mg_test']) && check_admin_referer('mg_monitor_test')) {
            $started = microtime(true);
            $collector = new MG_Collector();
            $data = $collector->all();
            $data['security'] = MG_Security_Checks::run();
            $test = array('data' => $data, 'seconds' => round(microtime(true) - $started, 1));
        }

        settings_errors(self::SLUG);
        ?>
        <div class="wrap">
            <h1>MEDIAGRAFIK Monitor</h1>
            <p>Tento plugin nic neposílá. Správa webů studia MEDIAGRAFIK si data načítá sama a prokazuje se API klíčem, který vložíte níže.</p>

            <form method="post">
                <?php wp_nonce_field('mg_monitor_settings'); ?>
                <input type="hidden" name="mg_monitor_action" value="save">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mg_monitor_key">API klíč</label></th>
                        <td>
                            <input type="password" class="regular-text code" id="mg_monitor_key" name="mg_monitor_key" autocomplete="off" placeholder="<?php echo esc_attr($hint !== '' ? 'uložený klíč končí na …' . $hint : 'mg_live_…'); ?>">
                            <p class="description"><?php echo $hint !== '' ? 'Klíč je uložený (…' . esc_html($hint) . '). Vložte nový jen při výměně.' : 'Klíč najdete ve Správě webů → detail webu → Nastavení → Připojení.'; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_monitor_hub">Adresa Správy webů</label></th>
                        <td>
                            <input type="url" class="regular-text code" id="mg_monitor_hub" name="mg_monitor_hub" value="<?php echo esc_attr($hub); ?>" placeholder="https://sprava.mediagrafik.cz">
                            <p class="description">Odtud si plugin bere své aktualizace. Nepovinné — bez vyplnění se použije výchozí adresa.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Přihlášení ze Správy webů</th>
                        <td>
                            <label><input type="checkbox" name="mg_monitor_allow_login" value="1"<?php checked(MG_Login::is_allowed()); ?>> Povolit přihlášení do administrace jedním klikem ze Správy webů</label>
                            <p class="description">Správa si vyžádá jednorázový odkaz (platí minutu) a přihlásí správcovský účet studia bez hesla. Hesla se nikam neukládají.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Stav</th>
                        <td>
                            <?php if ($last_seen !== '') : ?>
                                Hub naposledy načetl data <strong><?php echo esc_html(mysql2date('j. n. Y H:i', $last_seen)); ?></strong>.
                            <?php elseif ($hint !== '') : ?>
                                Klíč je uložený, hub se zatím neozval. Ve Správě webů klikněte na „Zkontrolovat teď".
                            <?php else : ?>
                                Nepřipojeno.
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Uložit'); ?>
            </form>

            <?php if ($hint !== '') : ?>
                <form method="post" style="margin-top:-10px">
                    <?php wp_nonce_field('mg_monitor_settings'); ?>
                    <input type="hidden" name="mg_monitor_action" value="disconnect">
                    <?php submit_button('Odpojit (smazat klíč)', 'delete', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <h2>Zkouška sběru dat</h2>
            <p>Ukáže, co hub uvidí. Kontrola aktualizací se ptá api.wordpress.org, může chvíli trvat.</p>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=' . self::SLUG . '&mg_test=1'), 'mg_monitor_test')); ?>">Otestovat sběr dat</a>

            <?php if ($test !== null) : ?>
                <p><strong>Hotovo za <?php echo esc_html((string) $test['seconds']); ?> s.</strong></p>
                <pre style="max-height:400px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px"><?php echo esc_html(wp_json_encode($test['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }
}
