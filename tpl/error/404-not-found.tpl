<link href="{{ app_css_folder }}/404.css" rel="stylesheet" media="all" />
<div class="wrapper">
    <div class="text_group">
        <p class="text_404">404</p>
        <p class="text_lost">The page you are looking for <br />has been lost in the sky.</p>
    </div>
    <div class="window_group">
        <div class="window_404">
            <div class="stars"></div>
        </div>
    </div>
</div>
<script>
    let starContainer = document.querySelector(".stars");

    for (let i = 0; i < 100; i++) {
        starContainer.innerHTML += '<div class="star"></div>';
    }
</script>
