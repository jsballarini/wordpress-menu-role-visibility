<?php
 /**
 * Plugin Name: Menu - Role Visibility
 * Plugin URI: https://github.com/jsballarini
 * Description: Adds a "minimum role" selector to each menu item. The item only appears to users with the chosen role (or higher), along with "Logged in only" and "Guests only" options.
 * Version: 0.0.1
 * Author: Juliano Ballarini
 * Author URI: https://github.com/jsballarini
 * Text Domain: menu-role-visibility
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.3
 * Requires PHP: 8.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Menu_Item_Visibilidade_Por_Role {
    private $meta_key = '_menu_item_min_role';

    public function __construct() {
        // Campo dentro de cada item de menu (WP 5.4+)
        add_action( 'wp_nav_menu_item_custom_fields', [ $this, 'render_field' ], 10, 4 );
        // Salvar meta por item
        add_action( 'wp_update_nav_menu_item', [ $this, 'save_item_meta' ], 10, 3 );
        // Filtrar itens que não devem aparecer
        add_filter( 'wp_nav_menu_objects', [ $this, 'filter_menu_items' ], 20, 2 );
    }

    /** Campo "Visibilidade por Função" em cada item do menu */
    public function render_field( $item_id, $item, $depth, $args ) {
        $current = get_post_meta( $item_id, $this->meta_key, true );
        $roles   = $this->get_role_labels_in_order();

        // ID/Name exclusivos para o item
        $field_id   = 'edit-menu-item-min-role-' . $item_id;
        $field_name = 'menu_item_min_role[' . $item_id . ']';

        ?>
        <p class="description description-wide">
            <label for="<?php echo esc_attr( $field_id ); ?>">
                <strong>Visibilidade por Função</strong><br>
                <select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" style="width:100%;">
                    <option value="" <?php selected( $current, '' ); ?>>Todos (padrão)</option>
                    <option value="logged_in" <?php selected( $current, 'logged_in' ); ?>>Apenas usuários logados</option>
                    <option value="guests" <?php selected( $current, 'guests' ); ?>>Apenas visitantes (não logados)</option>
                    <?php foreach ( $roles as $role => $label ) : ?>
                        <option value="<?php echo esc_attr( $role ); ?>" <?php selected( $current, $role ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span class="description" style="display:block;margin-top:4px;color:#666;font-size:12px;">
                Ex.: selecionar <em>Editor</em> exibirá este item para editores e administradores.
            </span>
        </p>
        <?php
    }

    /** Salvar meta do item ao atualizar o menu */
    public function save_item_meta( $menu_id, $menu_item_db_id, $args ) {
        // Respeita nonce padrão da tela de menus
        if ( ! isset( $_POST['update-nav-menu-nonce'] ) || ! wp_verify_nonce( $_POST['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
            return;
        }
        if ( isset( $_POST['menu_item_min_role'][ $menu_item_db_id ] ) ) {
            $val = sanitize_text_field( wp_unslash( $_POST['menu_item_min_role'][ $menu_item_db_id ] ) );
            update_post_meta( $menu_item_db_id, $this->meta_key, $val );
        } else {
            // Se o campo não vier, apaga (ex.: reset para padrão)
            delete_post_meta( $menu_item_db_id, $this->meta_key );
        }
    }

    /** Remove itens não permitidos (e também remove seus descendentes) */
    public function filter_menu_items( $items, $args ) {
        if ( empty( $items ) ) return $items;

        // Pré-calcula permissão por item
        $allowed_map = [];
        foreach ( $items as $item ) {
            $min_role = get_post_meta( $item->ID, $this->meta_key, true );
            $allowed_map[ $item->ID ] = $this->is_allowed( $min_role );
        }

        // Constrói conjunto de IDs permitidos respeitando ancestrais
        $by_id    = [];
        $allowed_ids = [];
        foreach ( $items as $item ) $by_id[ $item->ID ] = $item;

        // Função recursiva: permitido se o item e todos os ancestrais forem permitidos
        $ancestor_ok = function( $item_id ) use ( &$ancestor_ok, $by_id, $allowed_map ) {
            if ( empty( $by_id[ $item_id ] ) ) return false;
            $item = $by_id[ $item_id ];
            if ( empty( $allowed_map[ $item_id ] ) ) return false;
            if ( empty( $item->menu_item_parent ) ) return true;
            $parent_id = (int) $item->menu_item_parent;
            return $ancestor_ok( $parent_id );
        };

        $filtered = [];
        foreach ( $items as $item ) {
            if ( $ancestor_ok( $item->ID ) ) {
                $filtered[] = $item;
                $allowed_ids[ $item->ID ] = true;
            }
        }

        // Reparenting não é necessário: ao remover pai, filhos já são removidos
        return $filtered;
    }

    /** Regras de autorização por função/estado de login */
    private function is_allowed( $min_role ) {
        if ( $min_role === '' || $min_role === null ) {
            return true; // Todos
        }
        if ( $min_role === 'guests' ) {
            return ! is_user_logged_in();
        }
        if ( $min_role === 'logged_in' ) {
            return is_user_logged_in();
        }

        // Para funções: "a partir de"
        if ( ! is_user_logged_in() ) return false;

        $order = array_keys( $this->get_role_labels_in_order() ); // subscriber..administrator
        $wanted_index = array_search( $min_role, $order, true );
        if ( $wanted_index === false ) return false; // função inexistente no site

        $user = wp_get_current_user();
        $user_index = -1;
        foreach ( (array) $user->roles as $role ) {
            $idx = array_search( $role, $order, true );
            if ( $idx !== false && $idx > $user_index ) $user_index = $idx;
        }
        return $user_index >= $wanted_index;
    }

    /** Lista de funções existentes no site em ordem crescente de poder */
    private function get_role_labels_in_order() {
        // Ordem base do WP
        $order = [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ];

        global $wp_roles;
        if ( ! isset( $wp_roles ) ) $wp_roles = wp_roles();

        $labels = [];
        foreach ( $order as $role ) {
            if ( isset( $wp_roles->roles[ $role ] ) ) {
                $labels[ $role ] = translate_user_role( $wp_roles->roles[ $role ]['name'] );
            }
        }
        return $labels;
    }
}

new Menu_Item_Visibilidade_Por_Role();
