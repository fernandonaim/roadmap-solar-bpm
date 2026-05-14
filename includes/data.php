<?php
if (!defined('DATA_DIR')) {
    define('DATA_DIR', dirname(__DIR__) . '/data/');
    define('CONFIG_FILE', DATA_DIR . 'config.json');
}

function read_json(string $file): array {
    $path = DATA_DIR . $file;
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function write_json(string $file, array $data): bool {
    $path = DATA_DIR . $file;
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// ── Upstream ─────────────────────────────────────────────────────────────────

function upstream_all(): array {
    $data = read_json('upstream.json');
    return $data['items'] ?? [];
}

function upstream_find(string $id): ?array {
    foreach (upstream_all() as $item) {
        if ($item['id'] === $id) return $item;
    }
    return null;
}

function upstream_save(array $items): bool {
    return write_json('upstream.json', ['items' => $items]);
}

function upstream_create(array $fields): array {
    $items = upstream_all();
    $id    = 'ups_' . (time()) . '_' . rand(100, 999);
    $item  = array_merge([
        'id'          => $id,
        'title'       => '',
        'url'         => '',
        'phase'       => 'EPD',
        'etapa'       => '',
        'tipo'        => '',
        'tipo_evolucao' => '',
        'responsible' => '',
        'pf'          => 0,
        'horas'       => 0,
        'details'     => '',
        'created_at'  => date('Y-m-d'),
        'updated_at'  => date('Y-m-d'),
    ], $fields, ['id' => $id, 'created_at' => date('Y-m-d'), 'updated_at' => date('Y-m-d')]);
    $items[] = $item;
    upstream_save($items);
    return $item;
}

function upstream_update(string $id, array $fields): bool {
    $items = upstream_all();
    foreach ($items as &$item) {
        if ($item['id'] === $id) {
            $item = array_merge($item, $fields, ['id' => $id, 'updated_at' => date('Y-m-d')]);
            return upstream_save($items);
        }
    }
    return false;
}

function upstream_delete(string $id): bool {
    $items = array_filter(upstream_all(), fn($i) => $i['id'] !== $id);
    return upstream_save(array_values($items));
}

function fmt_pf(float $v): string {
    return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
}

function upstream_horas(array $item): float {
    $h = (float)($item['horas'] ?? 0);
    if ($h > 0) return $h;
    $pf = (float)($item['pf'] ?? 0);
    return $pf > 0 ? $pf * 12 : 0;
}

// ── Downstream ────────────────────────────────────────────────────────────────

function downstream_data(): array {
    $data = read_json('downstream.json');
    return is_array($data) ? $data : ['cycles' => []];
}

function downstream_save_data(array $data): bool {
    return write_json('downstream.json', $data);
}

function downstream_cycles(): array {
    return downstream_data()['cycles'] ?? [];
}

function downstream_cycle_find(string $cycle_id): ?array {
    foreach (downstream_cycles() as $c) {
        if ($c['id'] === $cycle_id) return $c;
    }
    return null;
}

function downstream_available_upstream(string $current_cycle_id = ''): array {
    $current_assigned = [];
    $item_cycles      = []; // upstream_id => ['label ano', ...] — priorizadas em outros ciclos
    $item_cand_cycles = []; // upstream_id => ['label ano', ...] — candidata não priorizada em outros ciclos

    foreach (downstream_cycles() as $cycle) {
        $info  = cycle_info($cycle['codigo']);
        $label = $info['label'] . ' ' . $cycle['ano'];

        if ($cycle['id'] === $current_cycle_id) {
            foreach ($cycle['candidatas'] as $id) $current_assigned[$id] = true;
            foreach ($cycle['priorizadas'] as $p)  $current_assigned[$p['upstream_id']] = true;
            foreach ($cycle['backlog'] as $id)      $current_assigned[$id] = true;
        } else {
            $prio_ids = array_column($cycle['priorizadas'], 'upstream_id');
            foreach ($cycle['priorizadas'] as $p) {
                $item_cycles[$p['upstream_id']][] = $label;
            }
            foreach ($cycle['candidatas'] as $id) {
                if (!in_array($id, $prio_ids)) {
                    $item_cand_cycles[$id][] = $label;
                }
            }
        }
    }

    $items = array_values(array_filter(upstream_all(), function ($i) use ($current_assigned) {
        return $i['phase'] === 'ERS'
            && $i['etapa'] === 'Especificação Aprovada'
            && !isset($current_assigned[$i['id']]);
    }));

    foreach ($items as &$item) {
        $item['_in_cycles']   = $item_cycles[$item['id']]      ?? [];
        $item['_cand_cycles'] = $item_cand_cycles[$item['id']] ?? [];
    }
    unset($item);

    // Sort: items not yet in any cycle come first
    usort($items, fn($a, $b) => count($a['_in_cycles']) <=> count($b['_in_cycles']));

    return $items;
}

function downstream_cycle_add_candidata(string $cycle_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $cycle_id) {
            if (!in_array($upstream_id, $cycle['candidatas'])) {
                $cycle['candidatas'][] = $upstream_id;
            }
            return downstream_save_data($data);
        }
    }
    return false;
}

function downstream_cycle_remove_candidata(string $cycle_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $cycle_id) {
            $cycle['candidatas'] = array_values(array_filter($cycle['candidatas'], fn($id) => $id !== $upstream_id));
            return downstream_save_data($data);
        }
    }
    return false;
}

function downstream_cycle_prioritize(string $cycle_id, string $upstream_id, float $mes1, float $mes2, float $exc, string $obs = ''): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] !== $cycle_id) continue;
        $cycle['candidatas'] = array_values(array_filter($cycle['candidatas'], fn($id) => $id !== $upstream_id));
        $cycle['backlog']    = array_values(array_filter($cycle['backlog'],    fn($id) => $id !== $upstream_id));
        $found = false;
        foreach ($cycle['priorizadas'] as &$p) {
            if ($p['upstream_id'] === $upstream_id) {
                $p['horas_mes1'] = $mes1; $p['horas_mes2'] = $mes2;
                $p['horas_excedente'] = $exc; $p['obs'] = $obs;
                $found = true; break;
            }
        }
        if (!$found) {
            $cycle['priorizadas'][] = ['upstream_id' => $upstream_id,
                'horas_mes1' => $mes1, 'horas_mes2' => $mes2, 'horas_excedente' => $exc, 'obs' => $obs];
        }
        return downstream_save_data($data);
    }
    return false;
}

function downstream_cycle_deprioritize(string $cycle_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $cycle_id) {
            $cycle['priorizadas'] = array_values(array_filter($cycle['priorizadas'], fn($p) => $p['upstream_id'] !== $upstream_id));
            if (!in_array($upstream_id, $cycle['candidatas'])) $cycle['candidatas'][] = $upstream_id;
            return downstream_save_data($data);
        }
    }
    return false;
}

function downstream_cycle_send_to_backlog(string $cycle_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $cycle_id) {
            $cycle['candidatas'] = array_values(array_filter($cycle['candidatas'], fn($id) => $id !== $upstream_id));
            if (!in_array($upstream_id, $cycle['backlog'])) $cycle['backlog'][] = $upstream_id;
            return downstream_save_data($data);
        }
    }
    return false;
}

function downstream_cycle_remove_backlog(string $cycle_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $cycle_id) {
            $cycle['backlog'] = array_values(array_filter($cycle['backlog'], fn($id) => $id !== $upstream_id));
            return downstream_save_data($data);
        }
    }
    return false;
}

function downstream_cycle_carry_forward(string $from_id, string $to_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $from_id)
            $cycle['backlog'] = array_values(array_filter($cycle['backlog'], fn($id) => $id !== $upstream_id));
        if ($cycle['id'] === $to_id && !in_array($upstream_id, $cycle['candidatas']))
            $cycle['candidatas'][] = $upstream_id;
    }
    return downstream_save_data($data);
}

function downstream_cycle_to_candidata(string $cycle_id, string $upstream_id): bool {
    $data = downstream_data();
    foreach ($data['cycles'] as &$cycle) {
        if ($cycle['id'] === $cycle_id) {
            $cycle['backlog'] = array_values(array_filter($cycle['backlog'], fn($id) => $id !== $upstream_id));
            if (!in_array($upstream_id, $cycle['candidatas'])) $cycle['candidatas'][] = $upstream_id;
            return downstream_save_data($data);
        }
    }
    return false;
}

function cycle_info(string $codigo): array {
    $map = [
        '02' => ['label' => 'Ciclo 02', 'periodo' => 'Jan/Fev', 'mes1' => 'Janeiro',   'mes2' => 'Fevereiro', 'end_month' => 2],
        '04' => ['label' => 'Ciclo 04', 'periodo' => 'Mar/Abr', 'mes1' => 'Março',     'mes2' => 'Abril',     'end_month' => 4],
        '06' => ['label' => 'Ciclo 06', 'periodo' => 'Mai/Jun', 'mes1' => 'Maio',      'mes2' => 'Junho',     'end_month' => 6],
        '08' => ['label' => 'Ciclo 08', 'periodo' => 'Jul/Ago', 'mes1' => 'Julho',     'mes2' => 'Agosto',    'end_month' => 8],
        '10' => ['label' => 'Ciclo 10', 'periodo' => 'Set/Out', 'mes1' => 'Setembro',  'mes2' => 'Outubro',   'end_month' => 10],
        '12' => ['label' => 'Ciclo 12', 'periodo' => 'Nov/Dez', 'mes1' => 'Novembro',  'mes2' => 'Dezembro',  'end_month' => 12],
    ];
    return $map[$codigo] ?? ['label' => "Ciclo $codigo", 'periodo' => '', 'mes1' => 'Mês 1', 'mes2' => 'Mês 2', 'end_month' => 0];
}

function cycle_effective_status(array $cycle): string {
    if (!empty($cycle['status_manual'])) return $cycle['status_manual'];
    return cycle_status($cycle['codigo'], $cycle['ano']);
}

function cycle_status(string $codigo, int $ano): string {
    $info = cycle_info($codigo);
    $end  = $info['end_month'];
    $y    = (int)date('Y');
    $m    = (int)date('n');
    if ($ano < $y) return 'done';
    if ($ano > $y) return 'upcoming';
    if ($m > $end) return 'done';
    if ($m >= ($end - 1)) return 'current';
    return 'upcoming';
}

function cycle_next_id(string $cycle_id): ?string {
    $codes = ['02', '04', '06', '08', '10', '12'];
    $cycle = downstream_cycle_find($cycle_id);
    if (!$cycle) return null;
    $idx = array_search($cycle['codigo'], $codes);
    if ($idx === false) return null;
    if ($idx < 5) return "ciclo_{$codes[$idx + 1]}_{$cycle['ano']}";
    return 'ciclo_02_' . ($cycle['ano'] + 1);
}

function cycle_prev_id(string $cycle_id): ?string {
    $codes = ['02', '04', '06', '08', '10', '12'];
    $cycle = downstream_cycle_find($cycle_id);
    if (!$cycle) return null;
    $idx = array_search($cycle['codigo'], $codes);
    if ($idx === false) return null;
    if ($idx > 0) return "ciclo_{$codes[$idx - 1]}_{$cycle['ano']}";
    return 'ciclo_12_' . ($cycle['ano'] - 1);
}

// ── Config helpers ────────────────────────────────────────────────────────────

function get_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = read_json('config.json');
    return $cfg ?? [];
}

function config_get(string $key): mixed {
    $cfg = get_config();
    return $cfg[$key] ?? null;
}

function responsible_color(string $name): string {
    foreach (config_get('responsibles') ?? [] as $r) {
        if ($r['name'] === $name) return $r['color'];
    }
    return '#888';
}

function responsible_short(string $name): string {
    foreach (config_get('responsibles') ?? [] as $r) {
        if ($r['name'] === $name) return $r['short'];
    }
    return substr($name, 0, 1);
}

function tipo_label(string $key): string {
    foreach (config_get('tipos') ?? [] as $t) {
        if ($t['key'] === $key) return $t['label'];
    }
    return $key;
}

function tipo_evolucao_label(string $key): string {
    foreach (config_get('tipos_evolucao') ?? [] as $t) {
        if ($t['key'] === $key) return $t['label'];
    }
    return $key;
}

function etapas_for_phase(string $phase): array {
    if ($phase === 'EPD') return config_get('epd_etapas') ?? [];
    if ($phase === 'ERS') return config_get('ers_etapas') ?? [];
    return [];
}
