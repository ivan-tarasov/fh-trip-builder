<div class="list-group list-group-checkable d-grid gap-2 border-0 w-auto my-2 {{ sb_sort_item_hide }}">
    <input type="radio"
           class="list-group-item-check pe-none"
           name="sort"
           id="sort{{ sb_sort_item_id }}"
           value="{{ sb_sort_item_value }}"
           onClick="document.getElementById('form_sort').submit()"
           {{ sb_sort_item_checked }}
    />
    <label class="list-group-item rounded-3 py-3" for="sort{{ sb_sort_item_id }}">
        {{ sb_sort_item_title }}
        <span class="d-block small opacity-50">{{ sb_sort_item_note }}</span>
    </label>
</div>
