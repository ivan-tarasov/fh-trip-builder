<div class="form-check form-switch ms-2">
  <input type="checkbox"
         role="switch"
         class="form-check-input mt-0"
         id="{{ airline-iata-code }}"
         name="airlines[]"
         value="{{ airline-iata-code }}"
         {{ airline-checked }}
  />
  <label class="form-check-label" for="{{ iata-code }}">{{ airline-title }}</label>
</div>
