{if $restricted_carriers}
<div class="alert alert-danger restrict-shipping-no-carrier">
    <i class="fa fa-ban"></i>
    <h4>No hay transportistas disponibles</h4>
    <p>Los siguientes transportistas no están disponibles hoy:</p>
    <ul>
    {foreach from=$restricted_carriers item=carrier}
        <li><strong>{$carrier.name|escape:'htmlall':'UTF-8'}:</strong> Disponible {$carrier.days|escape:'htmlall':'UTF-8'}</li>
    {/foreach}
    </ul>
    <p>Por favor, inténtalo en uno de los días disponibles.</p>
</div>
{/if}