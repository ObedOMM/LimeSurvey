<?php
Yii::app()->getController()->renderPartial(
    '/layouts/partial_modals/modal_header',
    ['modalTitle' => gT('User detail')]
);
?>

<div class="modal-body">
    <table class="table table-striped">
        <tr>
            <td><?=gT('User groups:')?></td>
            <td><?=join(', ',$usergroups)?></td>
        </tr>
        <tr>
            <td><?=gT('Created by:')?></td>
            <td><?=$oUser->parentUser['full_name']?></td>
        </tr>
        <tr>
            <td><?=gT('Surveys created:')?></td>
            <td><?=$oUser->surveysCreated?></td>
        </tr>
        <tr>
            <td><?=gT('Last login:')?></td>
            <td><?=$oUser->lastloginFormatted?></td>
        </tr>
    </table>
</div>

<div class="modal-footer modal-footer-buttons">
    <button id="exitForm" class="btn btn-outline-secondary"><?=gT('Close')?></button>
</div>
