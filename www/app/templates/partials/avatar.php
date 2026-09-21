<?php
/**
 * Kolečko s uživatelem — fotka, nebo iniciály (`.avatar` z návrhu).
 *
 * Iniciály nejsou nouzové řešení, ale výchozí stav — fotku má málokdo hned
 * a prázdné kolečko vypadá jako chyba. `?v=` je kus náhodného jména
 * souboru: po výměně fotky se změní celá URL, takže výdejová routa může
 * posílat dlouhou cache.
 *
 * @var \App\Core\View\View $this
 * @var array               $user  řádek uživatele (id, name, username, avatar)
 * @var string              $size  '' | 'sm'
 */

use App\Core\Auth\UserRepository;

$size = $size ?? '';
$name = UserRepository::displayName($user);
$file = (string) ($user['avatar'] ?? '');
?>
<?php if ($file !== ''): ?>
    <img class="avatar avatar--photo<?= $size !== '' ? ' avatar--' . $this->e($size) : '' ?>"
         src="<?= get_url('avatar/' . (int) $user['id']) . '?v=' . $this->e(substr($file, 0, 8)) ?>"
         alt="<?= $this->e($name) ?>" loading="lazy">
<?php else: ?>
    <?= get_avatar($name, (string) ($user['username'] ?? $name), $size) ?>
<?php endif; ?>
