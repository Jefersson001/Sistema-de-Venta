<div class="navigation-search konta-search" data-navigation-search>
<label class="module-search"><?= kontaIcon('search') ?><span class="visually-hidden">Buscar módulos y secciones</span><input type="search" placeholder="Buscar módulo o sección…" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="navigation-search-results" aria-keyshortcuts="Control+K Meta+K /" data-module-search><kbd>Ctrl K</kbd></label>
<div id="navigation-search-results" class="navigation-search-results" role="listbox" aria-label="Secciones encontradas" hidden></div><p class="visually-hidden" role="status" aria-live="polite" data-navigation-search-status></p>
<script type="application/json" data-navigation-search-data><?= json_encode($navigationSearchEntries??[],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</div>
