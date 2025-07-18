{if $restricted_carriers}
<div class="alert alert-warning restrict-shipping-cart-info">
    <i class="fa fa-exclamation-triangle"></i>
    <strong>Información de envío:</strong>
    <ul>
    {foreach from=$restricted_carriers item=carrier}
        <li><strong>{$carrier.name|escape:'htmlall':'UTF-8'}:</strong> No disponible hoy. Días disponibles: {$carrier.days|escape:'htmlall':'UTF-8'}</li>
    {/foreach}
    </ul>
</div>
{/if}