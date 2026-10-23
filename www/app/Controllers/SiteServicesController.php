<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth\UserRepository;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Core\Sites\SiteServices;
use InvalidArgumentException;

/**
 * Detail webu — záložka Služby (návrh `handoff-sluzby/detail-webu-sluzby.html`):
 * které služby na webu běží a odškrtávání kroků po spuštění webu.
 *
 * Řádek služby i úkolu je tlačítko formuláře s požadovaným novým stavem
 * (`service[gtm]=1`, `task[sitemap]=0`). Se skriptem (`site-services.js`)
 * se odešle na pozadí a odpověď v JSON přepíše počty na stránce; bez něj
 * je to obyčejný POST s návratem na záložku.
 */
final class SiteServicesController extends Controller
{
    use SiteHeaderTrait;

    public function show(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $state = $this->state($site);

        return $this->view('sites/services', $this->header($site, 'sluzby') + [
            'action' => get_url('weby/' . (int) $site['id'] . '/sluzby'),
            'serviceGroups' => $state['serviceGroups'],
            'servicesBadge' => $state['servicesBadge'],
            'taskGroups' => $state['taskGroups'],
            'progress' => $state['progress'],
        ]);
    }

    /** Přepnutí jedné služby nebo jednoho úkolu — ukládá se hned. */
    public function toggle(string $id): Response
    {
        $site = $this->siteOr404((int) $id);
        $services = $this->kernel->siteServices();
        [$kind, $key, $on] = $this->toggleInput();

        try {
            if ($kind === 'service') {
                $services->setService((int) $site['id'], $key, $on);
            } else {
                $userId = (int) ($this->kernel->auth()->current()['id'] ?? 0);
                $services->setTask((int) $site['id'], $key, $on, $userId > 0 ? $userId : null);
            }
        } catch (InvalidArgumentException) {
            throw HttpException::validation('Taková služba ani úkol neexistuje — načtěte stránku znovu.');
        }

        if (!$this->request()->wantsJson()) {
            return $this->redirect('weby/' . (int) $site['id'] . '/sluzby');
        }

        $state = $this->state($site);
        $item = $state['items'][$kind . ':' . $key];

        return $this->json([
            'ok' => true,
            'item' => ['on' => $item['on'], 'meta' => $item['meta'] ?? '', 'next' => $item['next']],
            'servicesBadge' => $state['servicesBadge'],
            'progress' => $state['progress'],
            'groups' => array_map(static fn (array $group): array => $group['badge'], array_column($state['taskGroups'], null, 'key')),
        ]);
    }

    /**
     * Co se přepíná: `service[klíč]=0|1` nebo `task[klíč]=0|1`.
     *
     * @return array{0: string, 1: string, 2: bool} druh, klíč, nový stav
     */
    private function toggleInput(): array
    {
        foreach (['service', 'task'] as $kind) {
            $field = $this->request()->input($kind);

            if (is_array($field) && count($field) === 1) {
                $key = (string) array_key_first($field);

                return [$kind, $key, in_array($field[$key], ['1', 1, true], true)];
            }
        }

        throw HttpException::validation('Chybí, co se má přepnout.');
    }

    /**
     * Hotová data záložky — pro šablonu i pro odpověď skriptu po přepnutí.
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function state(array $site): array
    {
        $services = $this->kernel->siteServices();
        $enabled = $services->enabled((int) $site['id']);
        $done = $services->done((int) $site['id']);
        $items = [];
        $serviceGroups = [];

        foreach (SiteServices::SERVICES as $groupKey => $group) {
            $rows = [];

            foreach ($group['items'] as $key => $label) {
                $on = isset($enabled[$key]);
                $rows[] = $items['service:' . $key] = ['name' => 'service[' . $key . ']', 'label' => $label, 'on' => $on, 'next' => $on ? '0' : '1'];
            }

            $serviceGroups[] = ['key' => $groupKey, 'label' => $group['label'], 'items' => $rows];
        }

        $taskGroups = [];
        $doneTotal = 0;
        $taskTotal = 0;
        $openSet = false;

        foreach (SiteServices::TASKS as $groupKey => $group) {
            $rows = [];
            $groupDone = 0;

            foreach ($group['items'] as $key => $label) {
                $task = $done[$key] ?? null;
                $meta = $task !== null ? trim(($task['user'] !== null ? UserRepository::shortName($task['user']) . ' · ' : '') . get_czech_date($task['done_at'])) : '';
                $rows[] = $items['task:' . $key] = ['name' => 'task[' . $key . ']', 'label' => $label, 'on' => $task !== null, 'next' => $task !== null ? '0' : '1', 'meta' => $meta];
                $groupDone += $task !== null ? 1 : 0;
            }

            $count = count($rows);
            $complete = $groupDone === $count;
            // Rozbalená je první skupina, kde ještě něco zbývá — tam se pokračuje.
            $open = !$complete && !$openSet;
            $openSet = $openSet || $open;

            $taskGroups[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'items' => $rows,
                'open' => $open,
                'badge' => $complete ? ['text' => 'Hotovo', 'tone' => 'ok'] : ['text' => $groupDone . ' / ' . $count, 'tone' => ''],
            ];
            $doneTotal += $groupDone;
            $taskTotal += $count;
        }

        $serviceTotal = count(SiteServices::keys(SiteServices::SERVICES));

        return [
            'items' => $items,
            'serviceGroups' => $serviceGroups,
            'servicesBadge' => count($enabled) . ' z ' . $serviceTotal . ' nasazeno',
            'taskGroups' => $taskGroups,
            'progress' => [
                'percent' => $taskTotal > 0 ? (int) round($doneTotal / $taskTotal * 100) : 0,
                'count' => $doneTotal . ' / ' . $taskTotal,
                'note' => $this->progressNote($taskTotal - $doneTotal, $site['launched_on'] ?? null),
            ],
        ];
    }

    /** „Zbývá 6 kroků · web spuštěn 13. 10. 2026" / „Vše po spuštění je hotové." */
    private function progressNote(int $remaining, ?string $launchedOn): string
    {
        if ($remaining <= 0) {
            return 'Vše po spuštění je hotové.';
        }

        return get_plural($remaining, 'Zbývá', 'Zbývají', 'Zbývá') . ' ' . get_count($remaining, 'krok', 'kroky', 'kroků')
            . ' · ' . ($launchedOn !== null && $launchedOn !== '' ? 'web spuštěn ' . get_czech_date($launchedOn) : 'datum spuštění webu doplňte v Nastavení');
    }

    /** @return array<string, mixed> */
    private function siteOr404(int $id): array
    {
        $site = $this->kernel->sites()->findWithSnapshot($id);

        if ($site === null || $site['removed_at'] !== null) {
            throw HttpException::notFound('Web neexistuje nebo byl odebrán z monitoringu.');
        }

        return $site;
    }
}
