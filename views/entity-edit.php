<?php
    $data = get_query_var('data');
    $editingEntity = $data['data']['editingEntity'];
?>

<div class="wrap">
    <h1>
        Editer une nouvelle entité
    </h1>

    <form method="post" class="sociallymap_formRSS">
        <input type="hidden" name="sociallymap_updateRSS" value="1">

        <table class="form-table">
            <tbody>
                <tr class="form-field form-required">
                    <th>
                        <label>Label</label>
                    </th>
                    <td>
                        <input name="sociallymap_label" value="<?php echo($editingEntity->name); ?>" 
                        class="sociallymap_formRSS_newFlux" placeholder="Mon entité">
                    </td>
                </tr>
                <tr class="form-field form-required">
                    <th>
                        <label>Identifiant de l'entité</label>
                    </th>
                    <td>
                        <input name="sociallymap_entityId" value="<?php echo($editingEntity->sm_entity_id); ?>" 
                        class="sociallymap_formRSS_newFlux" placeholder="Mon entité">
                    </td>
                </tr>
                <tr class="form-field form-required">
                    <th>
                        <label>Catégorie cible de la publication</label>
                    </th>
                    <td>
                        <?php foreach (get_categories(['hide_empty' => 0]) as $key => $value) { ?>    
                            <label class="listCats">
                            <?php echo $value->name;?>
                                <input  name="sociallymap_category[]" type="checkbox" 
                                        value="<?php echo get_cat_ID($value->name);?>"
                                <?php
                                foreach ($editingEntity->options->category as $key => $currentOption) {
                                    if ($value->cat_ID == $currentOption) {
                                        echo "checked";
                                    }
                                }
                                ?> >    
                            </label>
                        <?php } ?>
                    </td>
                </tr>
                <tr class="form-field form-required">
                    <th>
                        <label>Actif</label>
                    </th>
                    <td>
                        <input name="sociallymap_activate" class="sociallymap_formRSS_newFlux" type="checkbox" value="1"
                        <?php if ($editingEntity->display_type == "modal") { echo("checked"); } ?> >
                    </td>
                </tr>
                <tr class="form-field form-required">
                    <th>
                        <label>Ouverture du lien dans une fenêtre modale</label>
                    </th>
                    <td>
                        <input type="checkbox" name="sociallymap_display_type" class="sociallymap_formRSS_newFlux"
                        value="modal" <?php if ($editingEntity->activate == true) echo("checked"); ?> >
                    </td>
                </tr>
                <tr class="form-field form-required">
                    <th>
                        <label>Type de publication</label>
                    </th>
                    <td>
                        <select name="sociallymap_publish_type">
                            <option value ="publish" 
                                <?php if ($editingEntity->options->publish_type == 'publish') echo('selected'); ?>>
                                Publier
                            </option>
                            <option value ="draft" 
                                <?php if ($editingEntity->options->publish_type == 'draft') echo('selected'); ?>>
                                Brouillon
                            </option>
                            <option value ="pending" 
                                <?php if ($editingEntity->options->publish_type == 'pending') echo('selected'); ?>>
                                En attente de relecture
                            </option>
                            <option value ="private" 
                                <?php if ($editingEntity->options->publish_type == 'private') echo('selected'); ?>>
                                Privée
                            </option>
                            <option value ="future" 
                                <?php if ($editingEntity->options->publish_type == 'future') echo('selected'); ?>>
                                En attente de publication
                            </option>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit sociallymap_valid-submit">
            <button type="submit" name="submit" id="submit" class="button button-primary">
                <i class="dashicons-before dashicons-update sociallymap-icon-button"></i>
                Mettre a jour le lien
            </button>
        </p>
    </form>
</div>