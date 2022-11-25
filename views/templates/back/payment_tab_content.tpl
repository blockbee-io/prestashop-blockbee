<div class="tab-pane d-print-block fade show active" id="blockbeeHistoryTabContent" role="tabpanel" aria-labelledby="blockbeeHistoryTab">
    <ul style="list-style: none; padding: 0; margin: 0;">
        {foreach $history as $key => $data}
            <li>
                <strong>Callback UUID: </strong>{$key} <br/>
                <div class="tab-content" style="margin-top: 10px;">
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        {foreach $data as $dataKey => $dataItem}
                            <li><strong>{$dataKey}: </strong>
                                <p style="margin: 0; line-break: anywhere;">
                                    {if $dataKey === 'timestamp'}
                                        {$dataItem|date_format:"%H:%M, %e %B, %Y"}
                                    {else}
                                        {$dataItem}
                                    {/if}
                                </p>
                            </li>
                        {/foreach}
                    </ul>
                </div>
            </li>
        {/foreach}
    </ul>
</div>
<div class="tab-pane d-print-block fade show" id="blockbeeMetaTabContent" role="tabpanel" aria-labelledby="blockbeeMetaTab">
    <div class="tab-content">
        <ul style="list-style: none; padding: 0; margin: 0;">
            {foreach $meta_data as $key => $data}
                <li style="margin-bottom: 10px;">
                    <strong>{$key}: </strong>
                    <p style="margin: 0; line-break: anywhere;">
                        {if $key === 'blockbee_last_price_update' || $key === 'blockbee_order_created'}
                            {$data|date_format:"%H:%M, %e %B, %Y"}
                        {elseif $key === 'blockbee_qr_code' || $key === 'blockbee_qr_code_value' }
                            <img style="max-width: 100%; height: auto;" width="100" src="data:image/png;base64,{$data}"/>
                        {else}
                            {$data}
                        {/if}
                    </p>
                </li>
            {/foreach}
        </ul>
    </div>
</div>