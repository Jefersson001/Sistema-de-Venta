<?php
declare(strict_types=1);

/** Keep the edit action in the last column, after company and other decorators. */
function editActionsDecorateView(DOMDocument $dom, DOMXPath $xpath, string $current, ?callable $resolve = null, ?callable $allowed = null): void
{
    $resolve ??= static fn(string $url): ?array => transferRouteModel($url);
    $allowed ??= static function(array $model, int $id): bool {
        $action = str_ends_with($model['resource'], '.configuration') ? 'configure' : 'edit';
        return permissionHas($model['resource'], $action) && permissionRecord($model['resource'], $id, $action);
    };
    $editable = static fn(?array $model): bool => $model !== null && $model['save'] !== 'readonly'
        && empty($model['create_only']) && empty($model['singleton']);
    $models = []; $checks = [];
    $modelFor = static function(string $url) use (&$models, $resolve): ?array {
        if (!array_key_exists($url, $models)) $models[$url] = $resolve($url);
        return $models[$url];
    };
    $currentModel = $modelFor($current);
    parse_str(parse_url($current, PHP_URL_QUERY) ?? '', $query);
    $listing = empty($query['id']) && !isset($query['new'])
        && ($editable($currentModel) || ($currentModel['key'] ?? '') === 'treasury');

    foreach ($xpath->query('//main//table[contains(concat(" ",normalize-space(@class)," ")," contact-table ")]') as $table) {
        if ($table->hasAttribute('data-record-edit-actions')) continue;
        $heads = $xpath->query('./thead/tr', $table);
        if ($heads->length !== 1) continue;
        $head = $heads->item(0); $headers = iterator_to_array($xpath->query('./th|./td', $head));
        if (!$headers || array_filter($headers, static fn($cell) => (int)($cell->getAttribute('colspan') ?: 1) !== 1)) continue;
        $existingEdits = $xpath->query('./tbody/tr/td//a[starts-with(normalize-space(.),"Editar")]', $table)->length > 0;
        // Product lines and read-only reports retain their own controls.
        if (!$existingEdits && (!$listing || $xpath->query('ancestor::form', $table)->length)) continue;
        $count = count($headers); $actionIndex = null;
        foreach ($headers as $index=>$cell) {
            $label = str_replace('ó', 'o', mb_strtolower(trim($cell->textContent), 'UTF-8'));
            if (in_array($label, ['accion','acciones','editar'], true)) $actionIndex = $index;
        }
        $rows = iterator_to_array($xpath->query('./tbody/tr', $table)); $records = []; $hasRecord = false;
        foreach ($rows as $index=>$row) {
            $edits = []; $record = null;
            foreach ($xpath->query('./td//a[@href]', $row) as $link) {
                $isEdit = preg_match('/^Editar(?:\s|$)/ui', trim($link->textContent)) === 1;
                if ($isEdit) $edits[] = $link;
                $url = permissionAbsoluteUrl($link->getAttribute('href'), $current);
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
                $id = accessId($params['id'] ?? null);
                if (!$id || (!$isEdit && !str_contains(' '.$link->getAttribute('class').' ', ' contact-name '))) continue;
                $model = $modelFor($url);
                if (!$editable($model) || (!$isEdit && (!$listing || $model['table'] !== $currentModel['table']))) continue;
                if ($record === null || $isEdit) $record = ['url'=>$url,'id'=>$id,'model'=>$model,'name'=>trim($link->textContent)];
            }
            $records[$index] = ['record'=>$record,'edits'=>$edits];
            $hasRecord = $hasRecord || $record !== null;
        }
        // Empty primary lists still show the same column layout.
        $emptyList = !array_filter($rows, static function($row) use ($xpath): bool {
            $cells = $xpath->query('./td|./th', $row);
            return $cells->length !== 1 || (int)$cells->item(0)->getAttribute('colspan') <= 1;
        });
        if (!$hasRecord && (!$listing || !$emptyList || $xpath->query('ancestor::form', $table)->length || $existingEdits)) continue;
        $actionHead = $actionIndex === null ? $dom->createElement('th') : $headers[$actionIndex];
        $actionHead->nodeValue = 'Acciones'; $actionHead->setAttribute('scope', 'col');
        $actionHead->setAttribute('class', trim($actionHead->getAttribute('class').' record-edit-actions'));
        $head->appendChild($actionHead);
        $newCount = $count + ($actionIndex === null ? 1 : 0);
        foreach ($rows as $index=>$row) {
            $cells = iterator_to_array($xpath->query('./td|./th', $row));
            if (count($cells) === 1 && (int)$cells[0]->getAttribute('colspan') > 1) {
                $cells[0]->setAttribute('colspan', (string)$newCount); continue;
            }
            if (count($cells) !== $count) continue;
            $cell = $actionIndex === null ? $dom->createElement('td') : $cells[$actionIndex];
            foreach ($records[$index]['edits'] as $edit) $edit->parentNode?->removeChild($edit);
            $record = $records[$index]['record'];
            if ($record !== null) {
                $key = $record['model']['resource'].':'.$record['id'];
                $checks[$key] ??= $allowed($record['model'], $record['id']);
                if ($checks[$key]) {
                    $link = $dom->createElement('a', 'Editar');
                    $link->setAttribute('href', $record['url']);
                    $link->setAttribute('class', 'contact-button contact-button-link record-edit-link');
                    $link->setAttribute('aria-label', 'Editar registro '.$record['id']);
                    $cell->appendChild($link);
                }
            }
            if (!$cell->hasChildNodes()) $cell->appendChild($dom->createTextNode('—'));
            $cell->setAttribute('class', trim($cell->getAttribute('class').' record-edit-actions'));
            $row->appendChild($cell);
        }
        foreach ($xpath->query('./tfoot/tr', $table) as $footer) {
            $cells = $xpath->query('./td|./th', $footer);
            if ($cells->length === 1 && $cells->item(0)->hasAttribute('colspan')) $cells->item(0)->setAttribute('colspan', (string)$newCount);
            elseif ($cells->length === $count) $footer->appendChild($actionIndex === null ? $dom->createElement('td') : $cells->item($actionIndex));
        }
        $table->setAttribute('data-record-edit-actions', 'true');
    }
}
