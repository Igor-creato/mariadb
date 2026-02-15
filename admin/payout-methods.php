<?php

declare(strict_types=1);

/**
 * Файл для управления способами выплаты в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Payout_Methods_Admin
{

    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_payout_methods';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Обработка AJAX запросов
        add_action('wp_ajax_update_payout_method', [$this, 'handle_update_payout_method']);
        add_action('wp_ajax_add_payout_method', [$this, 'handle_add_payout_method']);
    }

    /**
     * Добавляем пункт меню в админке
     */
    public function add_admin_menu(): void
    {
        add_menu_page(
            'Кэшбэк',
            'Кэшбэк',
            'manage_options',
            'cashback-overview',
            [$this, 'render_overview_page'],
            'dashicons-money-alt',
            30
        );

        add_submenu_page(
            'cashback-overview',
            'Способы выплаты',
            'Способы выплаты',
            'manage_options',
            'cashback-payout-methods',
            [$this, 'render_payout_methods_page']
        );
    }

    /**
     * Отображаем страницу обзора кэшбэка
     */
    public function render_overview_page(): void
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }
?>
        <div class="wrap">
            <h1>Кэшбэк</h1>
            <p>Управление кэшбэком и способами выплаты</p>

            <div class="cashback-overview-stats">
                <div class="card">
                    <h2>Статистика кэшбэка</h2>
                    <p>Здесь будет отображаться статистика по кэшбэку</p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Отображаем страницу управления способами выплаты
     */
    public function render_payout_methods_page(): void
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Фильтр по статусу is_active
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $is_filtered = ($filter_status !== '' && $filter_status !== 'all');

        // Формируем WHERE-условие для фильтра
        $where_clause = '';
        if ($is_filtered) {
            $filter_value = intval($filter_status);
            $where_clause = $wpdb->prepare(" WHERE is_active = %d", $filter_value);
        }

        // Получаем все способы выплаты с учётом фильтра
        if ($is_filtered) {
            $payout_methods = $wpdb->get_results(
                "SELECT * FROM {$this->table_name}{$where_clause} ORDER BY sort_order ASC",
                ARRAY_A
            );
        } else {
            $payout_methods = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE %d = %d ORDER BY sort_order ASC", 1, 1),
                ARRAY_A
            );
        }

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            if ($_GET['message'] === 'added') {
                $message = '<div class="notice notice-success is-dismissible"><p>Способ выплаты успешно добавлен.</p></div>';
            } elseif ($_GET['message'] === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Способ выплаты успешно обновлен.</p></div>';
            }
        }

        ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">Способы выплаты</h1>
                <hr class="wp-header-end">

                <?php echo wp_kses_post($message); ?>

                <!-- Форма добавления нового способа выплаты -->
                <div class="card" id="add-payout-method-form" style="margin-bottom: 20px;">
                    <h2 class="title">Добавить способ выплаты</h2>
                    <form id="add-payout-method" method="post" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="slug">Slug:</label></th>
                                <td><input type="text" id="slug" name="slug" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="name">Название:</label></th>
                                <td><input type="text" id="name" name="name" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="is_active">Активен:</label></th>
                                <td>
                                    <select id="is_active" name="is_active">
                                        <option value="1">Да</option>
                                        <option value="0">Нет</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sort_order">Порядок сортировки:</label></th>
                                <td><input type="number" id="sort_order" name="sort_order" value="0" min="0" /></td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="add_payout_method" id="add-payout-method-submit" class="button button-primary" value="Добавить способ выплаты" />
                        </p>
                    </form>
                </div>

                <!-- Таблица существующих способов выплаты -->
                <h2 class="title">Существующие способы выплаты</h2>

                <!-- Фильтр по статусу активности -->
                <div class="search-box" style="margin-bottom: 15px;">
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="cashback-payout-methods" />
                        <label for="filter-status" class="screen-reader-text">Фильтр по статусу:</label>
                        <select id="filter-status" name="filter_status">
                            <option value="all" <?php selected($filter_status, ''); ?><?php selected($filter_status, 'all'); ?>>Все статусы</option>
                            <option value="1" <?php selected($filter_status, '1'); ?>>Активные</option>
                            <option value="0" <?php selected($filter_status, '0'); ?>>Не активные</option>
                        </select>
                        <input type="submit" class="button" value="Фильтровать" />
                        <?php if ($is_filtered): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-payout-methods')); ?>" class="button">Сбросить</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="wp-list-table-wrapper">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col">Slug</th>
                                <th scope="col">Название</th>
                                <th scope="col">Активен</th>
                                <th scope="col">Порядок сортировки</th>
                                <th scope="col">Действия</th>
                            </tr>
                        </thead>
                        <tbody id="payout-methods-tbody">
                            <?php if (!empty($payout_methods)): ?>
                                <?php foreach ($payout_methods as $method): ?>
                                    <tr data-id="<?php echo esc_attr($method['id']); ?>">
                                        <td class="edit-field" data-field="slug"><?php echo esc_html($method['slug']); ?></td>
                                        <td class="edit-field" data-field="name"><?php echo esc_html($method['name']); ?></td>
                                        <td class="edit-field" data-field="is_active">
                                            <?php echo $method['is_active'] ? 'Да' : 'Нет'; ?>
                                        </td>
                                        <td class="edit-field" data-field="sort_order"><?php echo esc_html($method['sort_order']); ?></td>
                                        <td>
                                            <button class="button button-secondary edit-btn">Редактировать</button>
                                            <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                            <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">Нет доступных способов выплаты.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Скрипты для работы с формой -->
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Обработка клика по кнопке "Редактировать"
                        $('.edit-btn').on('click', function() {
                            var row = $(this).closest('tr');
                            var cells = row.find('.edit-field');

                            cells.each(function() {
                                var cell = $(this);
                                var field = cell.data('field');
                                var currentValue = cell.text();

                                if (field === 'is_active') {
                                    // Для поля is_active создаем select
                                    var selectHtml = '<select class="edit-input" data-field="' + field + '">';
                                    selectHtml += '<option value="1"' + (currentValue === 'Да' ? ' selected' : '') + '>Да</option>';
                                    selectHtml += '<option value="0"' + (currentValue === 'Нет' ? ' selected' : '') + '>Нет</option>';
                                    selectHtml += '</select>';
                                    cell.html(selectHtml);
                                } else {
                                    // Для остальных полей создаем input
                                    cell.html('<input type="text" class="edit-input regular-text" data-field="' + field + '" value="' + currentValue + '" />');
                                }
                            });

                            row.find('.edit-btn').hide();
                            row.find('.save-btn, .cancel-btn').show();
                        });

                        // Обработка клика по кнопке "Отмена"
                        $('.cancel-btn').on('click', function() {
                            var row = $(this).closest('tr');
                            resetRowToViewMode(row);
                        });

                        // Обработка клика по кнопке "Сохранить"
                        $('.save-btn').on('click', function() {
                            var row = $(this).closest('tr');
                            var id = row.data('id');
                            var data = {
                                'action': 'update_payout_method',
                                'id': id,
                                'nonce': '<?php echo esc_js(wp_create_nonce('update_payout_method_nonce')); ?>'
                            };

                            row.find('.edit-input').each(function() {
                                var input = $(this);
                                var field = input.data('field');
                                data[field] = input.val();
                            });

                            $.post(ajaxurl, data, function(response) {
                                if (response.success) {
                                    // Обновляем значения в ячейках
                                    row.find('.edit-field[data-field="slug"]').text(response.data.slug);
                                    row.find('.edit-field[data-field="name"]').text(response.data.name);

                                    var isActiveText = response.data.is_active == 1 ? 'Да' : 'Нет';
                                    row.find('.edit-field[data-field="is_active"]').text(isActiveText);

                                    row.find('.edit-field[data-field="sort_order"]').text(response.data.sort_order);

                                    // Переключаем строку в режим просмотра без обновления значений
                                    row.find('.save-btn, .cancel-btn').hide();
                                    row.find('.edit-btn').show();

                                    // Показываем сообщение об успешном обновлении
                                    $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Способ выплаты успешно обновлен.</p></div>');
                                    setTimeout(function() {
                                        $('.notice-success').fadeOut().remove();
                                    }, 3000);
                                } else {
                                    alert('Ошибка при обновлении способа выплаты: ' + response.data.message);
                                }
                            });
                        });

                        // Сброс строки к режиму просмотра
                        function resetRowToViewMode(row) {
                            row.find('.edit-field').each(function() {
                                var cell = $(this);
                                var field = cell.data('field');
                                var currentValue = cell.find('.edit-input').val();

                                if (field === 'is_active') {
                                    var displayValue = currentValue == '1' ? 'Да' : 'Нет';
                                    cell.text(displayValue);
                                } else {
                                    cell.text(currentValue);
                                }
                            });

                            row.find('.save-btn, .cancel-btn').hide();
                            row.find('.edit-btn').show();
                        }

                        // Обработка формы добавления способа выплаты
                        $('#add-payout-method').on('submit', function(e) {
                            e.preventDefault();

                            var formData = {
                                'action': 'add_payout_method',
                                'nonce': '<?php echo esc_js(wp_create_nonce('add_payout_method_nonce')); ?>',
                                'slug': $('#slug').val(),
                                'name': $('#name').val(),
                                'is_active': $('#is_active').val(),
                                'sort_order': $('#sort_order').val()
                            };

                            $.post(ajaxurl, formData, function(response) {
                                if (response.success) {
                                    // Перезагружаем страницу для отображения новой записи
                                    window.location.href = window.location.href + '&message=added';
                                } else {
                                    alert('Ошибка при добавлении способа выплаты: ' + response.data.message);
                                }
                            });
                        });
                    });
                </script>
            </div>
    <?php
    }

    /**
     * Обработка AJAX запроса на обновление способа выплаты
     */
    public function handle_update_payout_method(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'update_payout_method_nonce')) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        global $wpdb;

        $id = intval($_POST['id']);
        $slug = sanitize_text_field($_POST['slug']);
        $name = sanitize_text_field($_POST['name']);
        $is_active = intval($_POST['is_active']);
        $sort_order = intval($_POST['sort_order']);

        // Валидация данных
        if (empty($slug) || empty($name)) {
            wp_send_json_error(['message' => 'Заполните все обязательные поля.']);
            return;
        }

        // Обновляем запись в базе данных
        $result = $wpdb->update(
            $this->table_name,
            [
                'slug' => $slug,
                'name' => $name,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%d'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Ошибка при обновлении способа выплаты в базе данных.']);
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success([
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
            'is_active' => $is_active,
            'sort_order' => $sort_order
        ]);
    }

    /**
     * Обработка AJAX запроса на добавление способа выплаты
     */
    public function handle_add_payout_method(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'add_payout_method_nonce')) {
            wp_send_json_error(['message' => 'Неверный токен безопасности.']);
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостаточно прав для выполнения этого действия.']);
            return;
        }

        global $wpdb;

        $slug = sanitize_text_field($_POST['slug']);
        $name = sanitize_text_field($_POST['name']);
        $is_active = intval($_POST['is_active']);
        $sort_order = intval($_POST['sort_order']);

        // Валидация данных
        if (empty($slug) || empty($name)) {
            wp_send_json_error(['message' => 'Заполните все обязательные поля.']);
            return;
        }

        // Проверяем, существует ли уже способ выплаты с таким slug
        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE slug = %s", $slug)
        );

        if ($existing > 0) {
            wp_send_json_error(['message' => 'Способ выплаты с таким slug уже существует.']);
            return;
        }

        // Добавляем новую запись в базу данных
        $result = $wpdb->insert(
            $this->table_name,
            [
                'slug' => $slug,
                'name' => $name,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ],
            ['%s', '%s', '%d', '%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Ошибка при добавлении способа выплаты в базе данных.']);
            return;
        }

        wp_send_json_success();
    }
}

// Инициализируем класс
$payout_methods_admin = new Cashback_Payout_Methods_Admin();
