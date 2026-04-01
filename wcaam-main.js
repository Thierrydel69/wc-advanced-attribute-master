/* WC Advanced Attribute Master - JS */
jQuery(function($){
    'use strict';

    // ── ÉTAT GLOBAL ──────────────────────────────────────────
    // Attributs affichés en textarea (texte long, terme WC unique par produit)
    var LONG_TEXT_ATTRS = {
        'pa_avantages':         true,
        'pa_inconvenients':     true,
        'pa_fonctionnalites':   true,
        'pa_fonctionnalites-ia':true
    };

    var state = {
        products:       [],   // produits chargés
        saveHistory:    {},   // { productId: [snapshot1, snapshot2, ...] } (max 5)
        sortCol:        'name',  // colonne de tri courante
        sortDir:        'asc',   // 'asc' ou 'desc'
        currentPage:    1,
        perPage:        20,
        totalPages:     1,
        totalProducts:  0,
        search:         '',
        visibleCols:    [],   // ex: ['product_cat','pa_color',…]
        dirtyRows:      {},   // { productId: true }
        selectedRows:   {},   // { productId: true }
        masterRow:      {},   // { taxonomy: [termSlug,…] }
        searchTimer:    null,
        acCache:        {}    // cache suggestions autocomplete
    };

    // Colonnes visibles par défaut = toutes cochées au départ
    function initVisibleCols() {
        state.visibleCols = [];
        $('.wcaam-col-toggle:checked').each(function(){
            state.visibleCols.push($(this).data('col'));
        });
    }

    // ── UTILITAIRES ──────────────────────────────────────────

    /** Affiche un toast */
    function toast(msg, type) {
        type = type || 'success';
        var $t = $('<div class="wcaam-toast '+type+'">'+msg+'</div>');
        $('#wcaam-toast-container').append($t);
        setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, 3500);
    }

    /** Requête AJAX générique */
    function ajax(action, data, cb) {
        data = $.extend({ action: action, nonce: WCAAM.nonce }, data);
        $.post(WCAAM.ajaxUrl, data, function(resp){
            if (resp && resp.success) {
                cb(null, resp.data);
            } else {
                cb(resp && resp.data ? resp.data : 'Erreur inconnue', null);
            }
        }, 'json').fail(function(){
            cb('Erreur réseau', null);
        });
    }

    /** Normalise la casse : première lettre majuscule */
    function normCase(str) {
        if (!str) return '';
        str = $.trim(str);
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /** Clé de comparaison insensible à la casse ET aux accents */
    function normalizeKey(str) {
        if (!str) return '';
        return str.toLowerCase()
            .replace(/[àâäáãå]/g,'a').replace(/[èéêë]/g,'e')
            .replace(/[îïíì]/g,'i').replace(/[ôöóòõ]/g,'o')
            .replace(/[ùûüú]/g,'u').replace(/ç/g,'c').replace(/ñ/g,'n')
            .replace(/\s+/g,' ').trim();
    }

    // ── CHARGEMENT DES PRODUITS ───────────────────────────────
    function loadProducts() {
        $('#wcaam-loading').removeClass('wcaam-hidden');
        $('#wcaam-table-wrap, #wcaam-empty').addClass('wcaam-hidden');

        ajax('wcaam_get_products', {
            page:     state.currentPage,
            per_page: state.perPage,
            search:   state.search,
            cols:     state.visibleCols.join(',')
        }, function(err, data){
            $('#wcaam-loading').addClass('wcaam-hidden');
            if (err) { toast(err, 'error'); return; }

            // Stocker une copie profonde des termes originaux pour le rollback
            data.products.forEach(function(p) {
                p.originalTerms = JSON.parse(JSON.stringify(p.terms));
            });
            state.products     = data.products;
            state.totalPages   = data.total_pages;
            state.totalProducts= data.total;
            state.dirtyRows    = {};
            state.selectedRows = {};
            state.saveHistory  = {};

            if (data.products.length === 0) {
                $('#wcaam-empty').removeClass('wcaam-hidden');
            } else {
                renderTable();
                $('#wcaam-table-wrap').removeClass('wcaam-hidden');
            }
            renderPagination();
            updateBulkBar();
        });
    }

    // ── RENDU DU TABLEAU ─────────────────────────────────────
    function renderTable() {
        sortProducts();
        renderThead();
        renderTbody();
    }

    function sortProducts() {
        state.products.sort(function(a, b){
            var av = '', bv = '';
            if (state.sortCol === 'name') {
                av = (a.name || '').toLowerCase();
                bv = (b.name || '').toLowerCase();
            }
            if (av < bv) return state.sortDir === 'asc' ? -1 : 1;
            if (av > bv) return state.sortDir === 'asc' ?  1 : -1;
            return 0;
        });
    }

    function renderThead() {
        var cols = state.visibleCols;
        var html = '<tr>';
        html += '<th class="wcaam-col-cb"><input type="checkbox" id="wcaam-cb-all" title="Tout sélectionner"></th>';
        html += '<th class="wcaam-col-img"></th>';
        // Colonne Produit avec tri alphabétique
        var sortIcon = state.sortCol === 'name'
            ? (state.sortDir === 'asc' ? '↑' : '↓') : '↕';
        html += '<th class="wcaam-col-name wcaam-sortable'
            + (state.sortCol==='name' ? ' sort-'+state.sortDir : '')
            + '" data-sort="name">'
            + 'Produit <em class="wcaam-sort-icon">'+sortIcon+'</em></th>';

        cols.forEach(function(col){
            var label = col === 'product_cat' ? 'Catégories' : getLabelForTaxonomy(col);
            html += '<th class="wcaam-col-attr" data-col="'+esc(col)+'">'+esc(label)+'</th>';
        });
        html += '<th class="wcaam-col-act">Action</th>';
        html += '</tr>';

        // Ligne Maître
        html += '<tr class="wcaam-row-master" id="wcaam-master-row">';
        html += '<td class="wcaam-col-cb"><span title="Ligne Maître">★</span></td>';
        html += '<td></td>';
        html += '<td class="wcaam-col-name" style="font-weight:700;font-size:12px;color:#059669;">LIGNE MAÎTRE<br><small style="font-weight:400;color:#64748b;">Définir → Appliquer en masse</small></td>';
        cols.forEach(function(col){
            html += '<td class="wcaam-col-attr" data-col="'+esc(col)+'">';
            html += buildTagInput('master', col, state.masterRow[col] || []);
            html += '</td>';
        });
        html += '<td class="wcaam-col-act"></td>';
        html += '</tr>';

        $('#wcaam-thead').html(html);
        bindTagInputEvents('#wcaam-master-row', 'master');
    }

    function renderTbody() {
        var html = '';
        state.products.forEach(function(p){
            var rowClass = state.selectedRows[p.id] ? ' wcaam-row-selected' : '';
            rowClass += state.dirtyRows[p.id] ? ' wcaam-row-dirty' : '';
            html += '<tr class="wcaam-product-row'+rowClass+'" data-id="'+p.id+'">';
            html += '<td class="wcaam-col-cb"><input type="checkbox" class="wcaam-cb-row" data-id="'+p.id+'"'+(state.selectedRows[p.id]?' checked':'')+' ></td>';
            // Image
            html += '<td class="wcaam-col-img">';
            if (p.image) {
                html += '<img src="'+esc(p.image)+'" class="wcaam-product-img" alt="">';
            } else {
                html += '<div class="wcaam-product-no-img">📦</div>';
            }
            html += '</td>';
            // Nom
            html += '<td class="wcaam-col-name"><span class="wcaam-product-name">'+esc(p.name)+'</span>';
            if (p.sku) html += '<span class="wcaam-product-sku">SKU: '+esc(p.sku)+'</span>';
            html += '</td>';
            // Colonnes attributs/catégories
            state.visibleCols.forEach(function(col){
                var currentTerms = p.terms[col] || [];
                html += '<td class="wcaam-col-attr" data-col="'+esc(col)+'">';
                html += buildTagInput(p.id, col, currentTerms);
                html += '</td>';
            });
            // Action
            html += '<td class="wcaam-col-act" style="white-space:nowrap">';
            html += '<button class="wcaam-btn wcaam-btn-success wcaam-btn-sm wcaam-btn-validate" data-id="'+p.id+'" disabled style="display:block;width:100%;margin-bottom:3px">✔ Valider</button>';
            html += '<button class="wcaam-btn wcaam-btn-ghost wcaam-btn-sm wcaam-btn-rollback" data-id="'+p.id+'" disabled style="display:block;width:100%;font-size:11px">↩ Annuler</button>';
            html += '</td>';
            html += '</tr>';
        });
        $('#wcaam-tbody').html(html);

        // Bind events sur chaque ligne produit
        state.products.forEach(function(p){
            bindTagInputEvents('tr[data-id="'+p.id+'"]', p.id);
        });

        // Tri par clic sur l'en-tête
        $('#wcaam-thead').off('click.sort').on('click.sort', '.wcaam-sortable', function(){
            var col = $(this).data('sort');
            if (state.sortCol === col) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortCol = col;
                state.sortDir = 'asc';
            }
            renderTable();
        });

        // Checkbox header
        $('#wcaam-cb-all').off('change').on('change', function(){
            var checked = this.checked;
            $('.wcaam-cb-row').prop('checked', checked);
            state.selectedRows = {};
            if (checked) {
                state.products.forEach(function(p){ state.selectedRows[p.id] = true; });
            }
            refreshRowClasses();
            updateBulkBar();
        });

        // Checkboxes individuelles
        $('#wcaam-tbody').off('change.cb').on('change.cb', '.wcaam-cb-row', function(){
            var id = parseInt($(this).data('id'));
            if (this.checked) state.selectedRows[id] = true;
            else delete state.selectedRows[id];
            refreshRowClasses();
            updateBulkBar();
        });

        // Bouton Valider
        $('#wcaam-tbody').off('click.validate').on('click.validate', '.wcaam-btn-validate', function(){
            var id = parseInt($(this).data('id'));
            saveProduct(id);
        });

        // Bouton Rollback : annule vers le snapshot précédent OU les originalTerms
        $('#wcaam-tbody').off('click.rollback').on('click.rollback', '.wcaam-btn-rollback', function(){
            var id = parseInt($(this).data('id'));
            var product = state.products.find(function(p){ return p.id === id; });
            var $row = $('tr[data-id="'+id+'"]');

            // Priorité : snapshot historique (après un save) > originalTerms (chargement initial)
            var restoreData = null;
            var histLabel = '';
            if (state.saveHistory[id] && state.saveHistory[id].length > 0) {
                restoreData = state.saveHistory[id].shift(); // dépiler le dernier snapshot
                histLabel = ' (état avant dernier enregistrement)';
            } else if (product && product.originalTerms) {
                restoreData = product.originalTerms;
                histLabel = ' (état initial au chargement)';
            }

            if (!restoreData) { toast('Aucun historique disponible.', 'warn'); return; }

            state.visibleCols.forEach(function(col){
                var $td = $row.find('td[data-col="'+col+'"]');
                var terms = restoreData[col] || [];
                $td.html(buildTagInput(id, col, terms));
            });
            bindTagInputEvents('tr[data-id="'+id+'"]', id);

            // S'il reste des snapshots, garder le bouton actif
            var hasMore = (state.saveHistory[id] && state.saveHistory[id].length > 0)
                       || (product && product.originalTerms);
            delete state.dirtyRows[id];
            $row.removeClass('wcaam-row-dirty');
            $row.find('.wcaam-btn-validate').prop('disabled', true);
            $row.find('.wcaam-btn-rollback').prop('disabled', !hasMore);

            toast('↩ Restauré' + histLabel, 'info');
        });
    }

    // ── CONSTRUCTION TAGINPUT ────────────────────────────────
    /**
     * Construit le HTML d'un champ tag-input.
     * @param {string|number} productId  'master' ou ID produit
     * @param {string}        taxonomy   ex: 'pa_color' ou 'product_cat'
     * @param {Array}         terms      [{slug, name, isNew?}, ...]
     */
    function buildTagInput(productId, taxonomy, terms) {
        var uid = 'ti_'+productId+'_'+taxonomy;

        // Attributs texte long → textarea
        var isLong = LONG_TEXT_ATTRS[taxonomy]
                  || (terms && terms.length > 0 && terms[0].isLongText);
        if (isLong) {
            var currentText = (terms && terms.length > 0) ? terms[0].name : '';
            var currentSlug = (terms && terms.length > 0) ? (terms[0].slug || '') : '';
            return '<textarea class="wcaam-ta" id="'+uid+'"'
                 + ' data-product="'+productId+'" data-taxonomy="'+taxonomy+'"'
                 + ' data-slug="'+esc(currentSlug)+'"'
                 + ' placeholder="Saisir le contenu…"'
                 + '>'+esc(currentText)+'</textarea>';
        }

        // taginput standard
        var html = '<div class="wcaam-taginput" id="'+uid+'" data-product="'+productId+'" data-taxonomy="'+taxonomy+'">';
        terms.forEach(function(t){
            html += buildTag(t);
        });
        html += '<input type="text" class="wcaam-taginput-field" placeholder="Ajouter…" autocomplete="off">';
        html += '<div class="wcaam-ac-dropdown wcaam-hidden"></div>';
        html += '</div>';
        return html;
    }

    function buildTag(term) {
        return '<span class="wcaam-tag'+(term.isNew?' is-new':'')+'" data-slug="'+esc(term.slug)+'" data-name="'+esc(term.name)+'" data-isnew="'+(term.isNew?1:0)+'" title="'+esc(term.name)+'">'
             + '<span class="wcaam-tag-text">'+esc(term.name)+(term.isNew?'  ✦':'')+'</span>'
             + '<button type="button" class="wcaam-tag-remove" tabindex="-1" title="Supprimer">×</button>'
             + '</span>';
    }

    // ── EVENTS TAGINPUT ──────────────────────────────────────
    function bindTagInputEvents(rowSelector, productId) {
        var $row = $(rowSelector);

        // Dirty tracking sur textareas
        $row.find('textarea.wcaam-ta').each(function(){
            var $ta = $(this);
            $ta.off('input.dirty').on('input.dirty', function(){
                markDirty($ta.data('product'));
            });
        });

        $row.find('.wcaam-taginput').each(function(){
            var $ti       = $(this);
            var taxonomy  = $ti.data('taxonomy');
            var $field    = $ti.find('.wcaam-taginput-field');
            var $dropdown = $ti.find('.wcaam-ac-dropdown');
            var acTimer   = null;
            var acIndex   = -1; // navigation clavier

            // Supprimer un tag
            $ti.off('click.remove').on('click.remove', '.wcaam-tag-remove', function(e){
                e.stopPropagation();
                $(this).closest('.wcaam-tag').remove();
                markDirty(productId);
                hideDropdown($dropdown);
            });

            // Focus → ouvrir AC avec toutes les valeurs
            // On vide le cache pour query='' à chaque focus pour garantir la fraîcheur
            $field.off('focus.ac').on('focus.ac', function(){
                var ck = taxonomy + '|';
                delete state.acCache[ck]; // forcer rechargement à l'ouverture
                fetchSuggestions($ti, taxonomy, '', $dropdown, acIndex);
            });

            // Frappe
            $field.off('input.ac').on('input.ac', function(){
                clearTimeout(acTimer);
                var val = $.trim($(this).val());
                acIndex = -1;
                acTimer = setTimeout(function(){
                    fetchSuggestions($ti, taxonomy, val, $dropdown, acIndex);
                }, 200);
            });

            // ── Coller (Ctrl+V) : traiter les séparateurs ──────────────
            $field.off('paste.ac').on('paste.ac', function(e){
                e.preventDefault();
                var pasted = '';
                if (e.originalEvent && e.originalEvent.clipboardData) {
                    pasted = e.originalEvent.clipboardData.getData('text/plain');
                } else if (window.clipboardData) {
                    pasted = window.clipboardData.getData('Text');
                }
                if (!pasted) return;

                // Détecter les séparateurs : retour à la ligne, point-virgule, tabulation, virgule
                var separators = /[\n\r;\t,]+/;
                var parts = pasted.split(separators)
                    .map(function(s){ return $.trim(s); })
                    .filter(function(s){ return s.length > 0; });

                if (parts.length <= 1) {
                    // Pas de séparateur : insérer normalement dans le champ
                    var current = $(this).val();
                    $(this).val(current + pasted);
                    $(this).trigger('input.ac');
                    return;
                }

                // Plusieurs valeurs : créer un tag pour chacune
                var pid = $ti.data('product');
                parts.forEach(function(part){
                    addTagFromText($ti, taxonomy, part, pid);
                });
                $(this).val('');
                hideDropdown($dropdown);
                toast(parts.length + ' valeur(s) collée(s).', 'success');
            });

            // Clavier : Entrée / Backspace / Flèches
            $field.off('keydown.ac').on('keydown.ac', function(e){
                var val = $.trim($(this).val());
                var $items = $dropdown.find('.wcaam-ac-item');

                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    if (acIndex >= 0 && $items.eq(acIndex).length) {
                        $items.eq(acIndex).trigger('click');
                    } else if (val.length > 0) {
                        addTagFromText($ti, taxonomy, val, productId);
                        $field.val('');
                        hideDropdown($dropdown);
                    }
                } else if (e.key === 'Backspace' && val === '') {
                    $ti.find('.wcaam-tag:last').remove();
                    markDirty(productId);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    acIndex = Math.min(acIndex+1, $items.length-1);
                    $items.removeClass('active').eq(acIndex).addClass('active');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    acIndex = Math.max(acIndex-1, 0);
                    $items.removeClass('active').eq(acIndex).addClass('active');
                } else if (e.key === 'Escape') {
                    hideDropdown($dropdown);
                }
            });

            // Clic en dehors → fermer
            $(document).off('click.ac_'+$ti.attr('id')).on('click.ac_'+$ti.attr('id'), function(e){
                if (!$ti.is(e.target) && $ti.has(e.target).length === 0) {
                    var val = $.trim($field.val());
                    if (val.length > 0) {
                        addTagFromText($ti, taxonomy, val, productId);
                        $field.val('');
                    }
                    hideDropdown($dropdown);
                }
            });
        });
    }

    /** Récupère les suggestions d'autocomplétion */
    function fetchSuggestions($ti, taxonomy, query, $dropdown, acIndex) {
        var cacheKey = taxonomy + '|' + query;
        if (state.acCache[cacheKey] !== undefined) {
            renderDropdown($ti, taxonomy, state.acCache[cacheKey], query, $dropdown);
            return;
        }
        ajax('wcaam_get_term_suggestions', { taxonomy: taxonomy, q: query }, function(err, data){
            if (err) return;
            state.acCache[cacheKey] = data.terms;
            renderDropdown($ti, taxonomy, data.terms, query, $dropdown);
        });
    }

    function renderDropdown($ti, taxonomy, terms, query, $dropdown) {
        var $field   = $ti.find('.wcaam-taginput-field');
        var existing = getTagSlugs($ti);
        var html     = '';

        // Filtrer ceux déjà ajoutés + filtrer par query
        var queryKey = normalizeKey(query);
        var filtered = terms.filter(function(t){
            if (existing.indexOf(t.slug) !== -1) return false;
            if (t.name.length > 120) return false;
            if (query === '') return true;
            return normalizeKey(t.name).indexOf(queryKey) !== -1;
        });
        // Trier : termes commençant par la query en premier, puis alphabétique
        filtered.sort(function(a, b){
            var ak = normalizeKey(a.name);
            var bk = normalizeKey(b.name);
            if (query) {
                var qk = normalizeKey(query);
                var aStarts = ak.indexOf(qk) === 0;
                var bStarts = bk.indexOf(qk) === 0;
                if (aStarts && !bStarts) return -1;
                if (!aStarts && bStarts) return 1;
            }
            return ak.localeCompare(bk);
        });

        if (filtered.length === 0 && query.length === 0) {
            html = '<div class="wcaam-ac-empty">Aucune valeur existante.</div>';
        } else {
            filtered.forEach(function(t, i){
                html += '<div class="wcaam-ac-item" data-slug="'+esc(t.slug)+'" data-name="'+esc(t.name)+'">';
                html += esc(t.name);
                html += '</div>';
            });
        }

        // Option "Créer …" si texte saisi non trouvé (insensible casse + accents)
        if (query.length > 0) {
            var normed    = normCase(query);
            var queryKey  = normalizeKey(query);
            // Vérifier dans TOUS les termes de la taxo (pas seulement les filtrés)
            var exactMatch = terms.some(function(t){ return normalizeKey(t.name) === queryKey; });
            if (!exactMatch) {
                html += '<div class="wcaam-ac-item wcaam-ac-item-create" data-slug="" data-name="'+esc(normed)+'" data-isnew="1">'
                      + 'Créer "<strong>'+esc(normed)+'</strong>"'
                      + '<span class="wcaam-ac-badge-new">Nouveau</span>'
                      + '</div>';
            }
        }

        $dropdown.html(html).removeClass('wcaam-hidden');

        // Clic sur item
        $dropdown.off('click.acitem').on('click.acitem', '.wcaam-ac-item', function(e){
            e.stopPropagation();
            var slug  = $(this).data('slug') || '';
            var name  = $(this).data('name') || '';
            var isNew = $(this).data('isnew') ? true : false;

            if (!name) return;
            addTag($ti, taxonomy, { slug: slug || slugify(name), name: name, isNew: isNew });
            $field.val('');
            hideDropdown($dropdown);
            markDirty($ti.data('product'));
        });
    }

    function hideDropdown($dropdown) {
        $dropdown.addClass('wcaam-hidden').html('');
    }

    function addTagFromText($ti, taxonomy, text, productId) {
        var normed  = normCase(text);
        var normedKey = normalizeKey(normed);
        // Vérifier doublons insensible casse + accents
        var existingKeys = $ti.find('.wcaam-tag').map(function(){
            return normalizeKey($(this).data('name'));
        }).get();
        if (existingKeys.indexOf(normedKey) !== -1) {
            toast('La valeur "'+normed+'" est déjà présente.', 'warn');
            return;
        }
        // Chercher dans TOUT le cache AC si le terme existe déjà (insensible casse)
        var match = null;
        Object.keys(state.acCache).forEach(function(k) {
            if (k.indexOf(taxonomy + '|') !== 0) return;
            (state.acCache[k] || []).forEach(function(t) {
                if (!match && normalizeKey(t.name) === normedKey) match = t;
            });
        });
        if (match) {
            addTag($ti, taxonomy, { slug: match.slug, name: match.name, isNew: false });
        } else {
            addTag($ti, taxonomy, { slug: slugify(normed), name: normed, isNew: true });
        }
        markDirty(productId);
    }

    function addTag($ti, taxonomy, term) {
        // Anti-doublon insensible casse + accents
        var termKey = normalizeKey(term.name);
        var existingKeys = $ti.find('.wcaam-tag').map(function(){
            return normalizeKey($(this).data('name'));
        }).get();
        if (existingKeys.indexOf(termKey) !== -1) return;
        $ti.find('.wcaam-taginput-field').before(buildTag(term));
    }

    function getTagSlugs($ti) {
        return $ti.find('.wcaam-tag').map(function(){ return $(this).data('slug'); }).get();
    }

    function getTagTerms($ti) {
        var terms = [];
        $ti.find('.wcaam-tag').each(function(){
            terms.push({
                slug:  $(this).data('slug'),
                name:  $(this).data('name'),
                isNew: $(this).data('isnew') == 1
            });
        });
        return terms;
    }

    /** Récupère les données d'une cellule (textarea ou taginput) */
    function getCellTerms($td) {
        var $ta = $td.find('textarea.wcaam-ta');
        if ($ta.length) {
            var text = $ta.val();
            if (!text || !text.trim()) return [];
            return [{ slug: $ta.data('slug') || '', name: text, isNew: false, isLongText: true }];
        }
        var $ti = $td.find('.wcaam-taginput');
        return $ti.length ? getTagTerms($ti) : [];
    }

    function slugify(str) {
        return str.toLowerCase()
                  .replace(/[àáâãäå]/g,'a').replace(/[èéêë]/g,'e')
                  .replace(/[ìíîï]/g,'i').replace(/[òóôõö]/g,'o')
                  .replace(/[ùúûü]/g,'u').replace(/[ç]/g,'c')
                  .replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
    }

    // ── DIRTY TRACKING ───────────────────────────────────────
    function markDirty(productId) {
        if (productId === 'master') return;
        state.dirtyRows[productId] = true;
        var $row = $('tr[data-id="'+productId+'"]');
        $row.addClass('wcaam-row-dirty');
        $row.find('.wcaam-btn-validate').prop('disabled', false);
        $row.find('.wcaam-btn-rollback').prop('disabled', false);
    }

    // ── SAUVEGARDE PRODUIT ───────────────────────────────────
    function saveSnapshot(productId) {
        var $row = $('tr[data-id="'+productId+'"]');
        var snapshot = {};
        state.visibleCols.forEach(function(col){
            var $td = $row.find('td[data-col="'+col+'"]');
            snapshot[col] = getCellTerms($td);
        });
        if (!state.saveHistory[productId]) state.saveHistory[productId] = [];
        state.saveHistory[productId].unshift(snapshot); // ajouter en tête
        if (state.saveHistory[productId].length > 5) state.saveHistory[productId].pop();
    }

    function saveProduct(productId) {
        var $row = $('tr[data-id="'+productId+'"]');
        $row.addClass('wcaam-saving');
        $row.find('.wcaam-btn-validate').prop('disabled', true).text('…');

        // Sauvegarder snapshot AVANT le save pour pouvoir annuler
        saveSnapshot(productId);

        var termsData = {};
        state.visibleCols.forEach(function(col){
            var $td = $row.find('td[data-col="'+col+'"]');
            termsData[col] = getCellTerms($td);
        });

        ajax('wcaam_save_product', {
            product_id: productId,
            terms:      JSON.stringify(termsData)
        }, function(err, data){
            $row.removeClass('wcaam-saving');
            $row.find('.wcaam-btn-validate').text('✔ Valider');
            if (err) {
                toast('Erreur : '+err, 'error');
                $row.find('.wcaam-btn-validate').prop('disabled', false);
                return;
            }
            delete state.dirtyRows[productId];
            $row.removeClass('wcaam-row-dirty');
            $row.find('.wcaam-btn-validate').prop('disabled', true);
            $row.find('.wcaam-btn-rollback').prop('disabled', true);
            // Mettre à jour originalTerms avec les nouvelles valeurs sauvegardées
            var savedProduct = state.products.find(function(p){ return p.id === productId; });
            if (savedProduct) {
                state.visibleCols.forEach(function(col){
                    var $td = $row.find('td[data-col="'+col+'"]');
                    var currentTerms = getCellTerms($td);
                    savedProduct.originalTerms[col] = JSON.parse(JSON.stringify(currentTerms));
                });
            }

            // Feedback visuel : flash vert
            $row.css('transition','background .3s').css('background','#d1fae5');
            setTimeout(function(){ $row.css('background',''); }, 800);

            // Si de nouveaux termes créés, afficher un toast spécial
            if (data.created_terms && data.created_terms.length > 0) {
                toast('✦ Nouveaux termes créés : '+data.created_terms.join(', ')+' — Produit sauvegardé !', 'info');
                // Invalider TOUT le cache AC pour les taxonomies concernées
                data.created_terms.forEach(function(termName){
                    // Vider toutes les entrées du cache (on ne connaît pas la taxonomy exacte ici)
                    state.acCache = {};
                });
                // Marquer les tags comme "non-nouveaux"
                $row.find('.wcaam-tag.is-new').removeClass('is-new').find('em').remove();
            } else {
                toast('Produit sauvegardé.', 'success');
            }
        });
    }

    // ── APPLICATION EN MASSE ─────────────────────────────────
    function applyBulk() {
        var selectedIds = Object.keys(state.selectedRows).map(Number).filter(Boolean);
        if (selectedIds.length === 0) { toast('Aucun produit sélectionné.', 'warn'); return; }

        // Récupérer les valeurs de la ligne maître
        var masterTerms = {};
        var hasValues = false;
        state.visibleCols.forEach(function(col){
            var $ti = $('#wcaam-master-row .wcaam-taginput[data-taxonomy="'+col+'"]');
            var terms = getTagTerms($ti);
            masterTerms[col] = terms;
            if (terms.length > 0) hasValues = true;
        });

        if (!hasValues) { toast('Définissez au moins une valeur dans la ligne maître.', 'warn'); return; }

        // Sauvegarder state.masterRow
        state.masterRow = masterTerms;

        $('#wcaam-btn-apply-bulk').prop('disabled', true).text('En cours…');

        ajax('wcaam_bulk_apply', {
            product_ids: JSON.stringify(selectedIds),
            terms:       JSON.stringify(masterTerms)
        }, function(err, data){
            $('#wcaam-btn-apply-bulk').prop('disabled', false).text('✔ Appliquer la ligne maître à la sélection');
            if (err) { toast('Erreur bulk : '+err, 'error'); return; }

            var msg = data.updated + ' produit(s) mis à jour.';
            if (data.created_terms && data.created_terms.length) {
                msg += ' Termes créés : '+data.created_terms.join(', ')+'.';
                toast(msg, 'info');
            } else {
                toast(msg, 'success');
            }
            // Recharger pour refléter les changements
            loadProducts();
        });
    }

    // ── PAGINATION ───────────────────────────────────────────
    function renderPagination() {
        var total = state.totalPages;
        var cur   = state.currentPage;
        var html  = '<span class="wcaam-page-info">'+state.totalProducts+' produits | Page '+cur+' / '+total+'</span>';

        html += '<button class="wcaam-page-btn" id="wcaam-pg-prev" '+(cur<=1?'disabled':'')+'>‹ Préc.</button>';

        // Afficher max 7 pages
        var start = Math.max(1, cur-3);
        var end   = Math.min(total, start+6);
        start     = Math.max(1, end-6);

        for (var i=start; i<=end; i++) {
            html += '<button class="wcaam-page-btn'+(i===cur?' active':'')+'" data-page="'+i+'">'+i+'</button>';
        }

        html += '<button class="wcaam-page-btn" id="wcaam-pg-next" '+(cur>=total?'disabled':'')+'>Suiv. ›</button>';
        $('#wcaam-pagination').html(html);

        $('#wcaam-pg-prev').off('click').on('click', function(){
            if (state.currentPage > 1) { state.currentPage--; loadProducts(); }
        });
        $('#wcaam-pg-next').off('click').on('click', function(){
            if (state.currentPage < state.totalPages) { state.currentPage++; loadProducts(); }
        });
        $('#wcaam-pagination').off('click.page').on('click.page', '.wcaam-page-btn[data-page]', function(){
            var p = parseInt($(this).data('page'));
            if (p !== state.currentPage) { state.currentPage = p; loadProducts(); }
        });
    }

    // ── BULK BAR ─────────────────────────────────────────────
    function updateBulkBar() {
        var count = Object.keys(state.selectedRows).length;
        if (count > 0) {
            $('#wcaam-bulk-bar').removeClass('wcaam-hidden');
            $('#wcaam-bulk-count').text(count + ' produit(s) sélectionné(s)');
        } else {
            $('#wcaam-bulk-bar').addClass('wcaam-hidden');
        }
    }

    function refreshRowClasses() {
        state.products.forEach(function(p){
            var $row = $('tr[data-id="'+p.id+'"]');
            if (state.selectedRows[p.id]) $row.addClass('wcaam-row-selected');
            else $row.removeClass('wcaam-row-selected');
        });
    }

    // ── COLONNES VISIBLES ────────────────────────────────────
    function getLabelForTaxonomy(taxonomy) {
        if (!WCAAM.allAttributes || !Array.isArray(WCAAM.allAttributes)) return taxonomy;
        var found = null;
        for (var i = 0; i < WCAAM.allAttributes.length; i++) {
            if (WCAAM.allAttributes[i].taxonomy === taxonomy) { found = WCAAM.allAttributes[i]; break; }
        }
        if (found) return found.label;
        // Fallback : nettoyer le slug (pa_mon-attribut → Mon attribut)
        return taxonomy.replace('pa_','').replace(/-/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
    }

    // ── GESTION DU DROPDOWN COLONNES ─────────────────────────
    function bindColDropdown() {
        $('#wcaam-btn-columns').on('click', function(e){
            e.stopPropagation();
            $('#wcaam-col-dropdown').toggleClass('wcaam-hidden');
        });
        $('#wcaam-col-close').on('click', function(){
            $('#wcaam-col-dropdown').addClass('wcaam-hidden');
        });
        $(document).on('click', function(e){
            if (!$('#wcaam-col-dropdown').hasClass('wcaam-hidden') &&
                !$('#wcaam-col-selector-wrap').find(e.target).length) {
                $('#wcaam-col-dropdown').addClass('wcaam-hidden');
            }
        });

        // Toggle colonne
        $(document).on('change', '.wcaam-col-toggle', function(){
            var col = $(this).data('col');
            if (this.checked) {
                if (state.visibleCols.indexOf(col) === -1) state.visibleCols.push(col);
            } else {
                state.visibleCols = state.visibleCols.filter(function(c){ return c !== col; });
            }
            renderTable();
        });
    }

    // ── MODAL AJOUT ATTRIBUT ─────────────────────────────────
    function bindModal() {
        $('#wcaam-col-add, #wcaam-btn-columns').on('click', function(){
            // Filtrer les attributs déjà affichés
            $('#wcaam-modal-attr-select option').each(function(){
                var val = $(this).val();
                if (!val) return;
                $(this).prop('hidden', state.visibleCols.indexOf(val) !== -1);
            });
        });
        $('#wcaam-col-add').on('click', function(e){
            e.stopPropagation();
            $('#wcaam-col-dropdown').addClass('wcaam-hidden');
            $('#wcaam-modal-overlay').removeClass('wcaam-hidden');
        });
        $('#wcaam-modal-close, #wcaam-modal-cancel').on('click', function(){
            $('#wcaam-modal-overlay').addClass('wcaam-hidden');
        });
        $('#wcaam-modal-overlay').on('click', function(e){
            if (e.target === this) $('#wcaam-modal-overlay').addClass('wcaam-hidden');
        });
        $('#wcaam-modal-confirm').on('click', function(){
            var $sel = $('#wcaam-modal-attr-select');
            var val  = $sel.val();
            var label= $sel.find(':selected').data('label') || val;
            if (!val) { toast('Choisir un attribut.', 'warn'); return; }
            if (state.visibleCols.indexOf(val) !== -1) { toast('Colonne déjà affichée.', 'warn'); return; }

            state.visibleCols.push(val);
            // Cocher la checkbox correspondante
            $('.wcaam-col-toggle[data-col="'+val+'"]').prop('checked', true);
            renderTable();
            $('#wcaam-modal-overlay').addClass('wcaam-hidden');
            $sel.val('');
            toast('Colonne "'+label+'" ajoutée.', 'success');
        });
    }

    // ── ÉCHAPPEMENT HTML ─────────────────────────────────────
    function esc(str) {
        if (str === undefined || str === null) return '';
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    // ── INITIALISATION ───────────────────────────────────────
    $(function(){
        // Créer le conteneur toast
        $('body').append('<div id="wcaam-toast-container"></div>');

        initVisibleCols();
        bindColDropdown();
        bindModal();

        // Recherche avec debounce
        $('#wcaam-search').on('input', function(){
            clearTimeout(state.searchTimer);
            var val = $.trim($(this).val());
            state.searchTimer = setTimeout(function(){
                state.search      = val;
                state.currentPage = 1;
                loadProducts();
            }, 400);
        });

        // Per page
        $('#wcaam-per-page').on('change', function(){
            state.perPage     = parseInt($(this).val());
            state.currentPage = 1;
            loadProducts();
        });

        // Reload
        $('#wcaam-btn-reload').on('click', function(){
            state.acCache = {};
            loadProducts();
        });

        // Appliquer en masse
        $('#wcaam-btn-apply-bulk').on('click', applyBulk);

        // ── Coller une colonne CSV ──────────────────────────────────────
        // L'utilisateur copie une colonne depuis Excel/CSV (20 lignes)
        // et colle sur la colonne sélectionnée des produits cochés
        var $pasteModal = $('<div id="wcaam-paste-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999999;align-items:center;justify-content:center;">'
            + '<div style="background:#fff;border-radius:8px;padding:20px;width:480px;max-width:95vw;max-height:90vh;overflow:auto;">'
            + '<h3 style="margin:0 0 12px;font-size:15px;">📋 Coller une colonne CSV</h3>'
            + '<p style="font-size:13px;color:#64748b;margin:0 0 10px;">Sélectionne la colonne cible, puis colle tes valeurs (une par ligne).<br>Les valeurs seront appliquées dans l\'ordre aux produits sélectionnés.</p>'
            + '<div style="margin-bottom:10px;">'
            + '<label style="font-size:13px;font-weight:600;">Colonne cible :</label><br>'
            + '<select id="wcaam-paste-col" style="width:100%;padding:6px;border:1px solid #e2e8f0;border-radius:4px;margin-top:4px;font-size:13px;"></select>'
            + '</div>'
            + '<div style="margin-bottom:10px;">'
            + '<label style="font-size:13px;font-weight:600;">Valeurs à coller (une par ligne) :</label><br>'
            + '<textarea id="wcaam-paste-values" placeholder="Colle ici tes valeurs depuis Excel/CSV..." style="width:100%;height:180px;font-size:12px;border:1px solid #e2e8f0;border-radius:4px;padding:8px;margin-top:4px;box-sizing:border-box;font-family:monospace;resize:vertical;"></textarea>'
            + '</div>'
            + '<p id="wcaam-paste-info" style="font-size:12px;color:#64748b;margin:0 0 12px;"></p>'
            + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
            + '<button id="wcaam-paste-apply" class="wcaam-btn wcaam-btn-primary">✔ Appliquer</button>'
            + '<button id="wcaam-paste-cancel" class="wcaam-btn wcaam-btn-ghost">Annuler</button>'
            + '</div></div></div>');
        $('body').append($pasteModal);

        // Bouton dans la bulk bar
        if ($('#wcaam-btn-paste-col').length === 0) {
            $('#wcaam-bulk-bar').append(
                '<button id="wcaam-btn-paste-col" class="wcaam-btn wcaam-btn-secondary wcaam-btn-sm" style="margin-left:4px;">📋 Coller colonne CSV</button>'
            );
        }

        $('#wcaam-btn-paste-col').off('click').on('click', function(){
            var selectedIds = Object.keys(state.selectedRows).map(Number).filter(Boolean);
            if (selectedIds.length === 0) { toast('Aucun produit coché.', 'warn'); return; }

            // Remplir le select des colonnes disponibles
            var $colSelect = $('#wcaam-paste-col');
            $colSelect.empty();
            $colSelect.append('<option value="product_cat">Catégories</option>');
            state.visibleCols.forEach(function(col){
                if (col === 'product_cat') return;
                var label = getLabelForTaxonomy(col);
                $colSelect.append('<option value="'+esc(col)+'">'+esc(label)+'</option>');
            });

            $('#wcaam-paste-info').text(selectedIds.length + ' produit(s) coché(s) - valeurs appliquées dans ordre affiché.');
            $('#wcaam-paste-values').val('');
            $pasteModal.css('display','flex');
            setTimeout(function(){ $('#wcaam-paste-values').focus(); }, 100);
        });

        $('#wcaam-paste-cancel').on('click', function(){ $pasteModal.hide(); });
        $pasteModal.on('click', function(e){ if(e.target===this) $pasteModal.hide(); });

        $('#wcaam-paste-apply').on('click', function(){
            var col    = $('#wcaam-paste-col').val();
            var rawText = $('#wcaam-paste-values').val();
            var lines  = rawText.split(/[\n\r]+/).map(function(l){ return l.trim(); }).filter(function(l){ return l.length > 0; });

            if (!col) { toast('Sélectionne une colonne.', 'warn'); return; }
            if (lines.length === 0) { toast('Aucune valeur à coller.', 'warn'); return; }

            // Récupérer les produits sélectionnés dans l'ordre d'affichage
            var selectedIds = Object.keys(state.selectedRows).map(Number).filter(Boolean);
            var orderedProducts = state.products.filter(function(p){ return state.selectedRows[p.id]; });

            if (lines.length < orderedProducts.length) {
                if (!confirm('Attention : ' + lines.length + ' valeur(s) pour ' + orderedProducts.length + ' produit(s). Les derniers produits seront ignorés.')) return;
            }

            var applied = 0;
            orderedProducts.forEach(function(p, idx){
                if (idx >= lines.length) return;
                var line = lines[idx];
                if (!line) return;

                var $row = $('tr[data-id="'+p.id+'"]');
                if (!$row.length) return;

                var $td = $row.find('td[data-col="'+col+'"]');
                if (!$td.length) return;

                // Séparer les valeurs de la ligne (virgule ou point-virgule)
                var vals = line.split(/[,;]+/).map(function(v){ return v.trim(); }).filter(function(v){ return v; });

                // Vider le taginput actuel et ajouter les nouvelles valeurs
                $td.find('.wcaam-tag').remove();
                vals.forEach(function(val){
                    addTagFromText($td.find('.wcaam-taginput'), col, val, p.id);
                });
                markDirty(p.id);
                applied++;
            });

            $pasteModal.hide();
            toast(applied + ' produit(s) mis à jour. Clique ✔ Valider sur chaque ligne ou utilise "Appliquer en masse".', 'info');
        });

        // Désélectionner tout
        $('#wcaam-btn-deselect-all').on('click', function(){
            state.selectedRows = {};
            $('.wcaam-cb-row').prop('checked', false);
            $('#wcaam-cb-all').prop('checked', false);
            refreshRowClasses();
            updateBulkBar();
        });

        // Chargement initial : d'abord charger les attributs, puis les produits
        ajax('wcaam_get_attributes', {}, function(err, data) {
            if (!err && data && data.attributes) {
                WCAAM.allAttributes = data.attributes;
                // Mettre à jour les labels dans le dropdown colonnes
                $('.wcaam-col-toggle').each(function() {
                    var col = $(this).data('col');
                    if (!col || col === 'product_cat') return;
                    var label = getLabelForTaxonomy(col);
                    $(this).closest('.wcaam-col-item').contents().filter(function(){ return this.nodeType === 3; }).last().replaceWith(label);
                });
            }
            loadProducts();
        });

    });

});
