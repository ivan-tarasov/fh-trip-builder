<style>
    body {
        overflow: hidden;
    }
</style>
<img src="{{ image_url }}" />
<script>
    setTimeout(function() {
        window.location.replace("/search/?{{ search_params }}");
    }, 100);
</script>
