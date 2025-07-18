{if $restricted_carriers}
<div class="alert alert-info restrict-shipping-product-info">
    <i class="fa fa-info-circle"></i>
    <strong>Información de envío:</strong>
    <p style="margin-top: 10px; margin-bottom: 5px;">Algunos transportistas no están disponibles hoy:</p>
    <ul style="margin-bottom: 0;">
    {foreach from=$restricted_carriers item=carrier}
        <li><strong>{$carrier.name|escape:'htmlall':'UTF-8'}:</strong> Disponible {$carrier.days|escape:'htmlall':'UTF-8'}</li>
    {/foreach}
    </ul>
</div>
{/if}