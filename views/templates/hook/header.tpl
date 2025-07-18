<script type="text/javascript">
var restrict_shipping_vars = {
    restrict_shipping_enabled: {$restrict_shipping_vars.restrict_shipping_enabled|intval},
    restrict_shipping_carriers: {$restrict_shipping_vars.restrict_shipping_carriers|json_encode},
    restrict_shipping_message: '{$restrict_shipping_vars.restrict_shipping_message|escape:'javascript':'UTF-8'}',
    current_day: {$restrict_shipping_vars.current_day|intval},
    days_names: {$restrict_shipping_vars.days_names|json_encode}
};
</script>