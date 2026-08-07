<?php
$session = App\Models\SearchSession::latest('id')->first();
echo "ID={$session->id} total_budget=" . var_export($session->total_budget, true)
    . " adults={$session->adults_count} children=" . json_encode($session->children_ages)
    . " rooms=" . var_export($session->number_of_rooms, true)
    . " created={$session->created_at}\n";
