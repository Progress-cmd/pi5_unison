<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>
<div id="sup-search-form">
    <div id="search-form" class="containers">
        <span class="material-symbols-outlined">search</span>
        <input type="text" placeholder="Titre, artiste ou playlist…" id="search-entry"
               autocomplete="off" spellcheck="false">
        <button type="button" id="search-clear" class="material-symbols-outlined"
                title="Effacer" hidden>close</button>
    </div>

    <!-- Onglets de filtrage, remplis par search.js une fois la recherche faite -->
    <nav id="search-filters" hidden></nav>
</div>

<article class="containers">
    <div class="body-bar">
        <div id="search-results" class="content"></div>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/search.js') ?>"></script>
