<?php

declare(strict_types=1);

/**
 * Файл для управления банками в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Bank_Management_Admin
{

    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_banks';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Обработка AJAX запросов
        add_action('wp_ajax_update_bank', [$this, 'handle_update_bank']);
        add_action('wp_ajax_add_bank', [$this, 'handle_add_bank']);
    }

    /**
     * Добавляем пункт меню в админке
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            'Банки',
            'Банки',
            'manage_options',
            'cashback-banks',
            [$this, 'render_banks_page']
        );
    }

    /**
     * Отображаем страницу управления банками
     */
    public function render_banks_page(): void
    {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Пагинация: настройки
        $per_page = 10;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Общее количество банков
        $total_banks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        // Получаем банки с учётом пагинации
        $banks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        // Вычисляем общее количество страниц
        $total_pages = (int) ceil($total_banks / $per_page);

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            $msg_type = sanitize_text_field($_GET['message']);
            if ($msg_type === 'added') {
                $message = '<div class="notice notice-success is-dismissible"><p>Банк успешно добавлен.</p></div>';
            } elseif ($msg_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Банк успешно обновлен.</p></div>';
            } elseif ($msg_type === 'deleted') {
                $message = '<div class="notice notice-success is-dismissible"><p>Банк успешно удален.</p></div>';
            }
        }
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Банки</h1>
            <hr class="wp-header-end">

            <?php echo $message; ?>

            <!-- Форма добавления нового банка -->
            <div class="card" id="add-bank-form" style="margin-bottom: 20px;">
                <h2 class="title">Добавить банк</h2>
                <form id="add-bank" method="post" action="">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="bank_code">Код банка:</label></th>
                            <td><input type="text" id="bank_code" name="bank_code" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="name">Полное название:</label></th>
                            <td><input type="text" id="name" name="name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="short_name">Краткое название:</label></th>
                            <td><input type="text" id="short_name" name="short_name" class="regular-text" /></td>
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
                        <input type="submit" name="add_bank" id="add-bank-submit" class="button button-primary" value="Добавить банк" />
                    </p>
                </form>
            </div>

            <!-- Таблица существующих банков -->
            <h2 class="title">Существующие банки</h2>

            <div class="wp-list-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">Код банка</th>
                            <th scope="col">Полное название</th>
                            <th scope="col">Краткое название</th>
                            <th scope="col">Активен</th>
                            <th scope="col">Порядок сортировки</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="banks-tbody">
                        <?php if (!empty($banks)): ?>
                            <?php foreach ($banks as $bank): ?>
                                <tr data-id="<?php echo esc_attr($bank['id']); ?>">
                                    <td class="edit-field" data-field="bank_code"><?php echo esc_html($bank['bank_code']); ?></td>
                                    <td class="edit-field" data-field="name"><?php echo esc_html($bank['name']); ?></td>
                                    <td class="edit-field" data-field="short_name"><?php echo esc_html($bank['short_name'] ?: ''); ?></td>
                                    <td class="edit-field" data-field="is_active">
                                        <?php echo $bank['is_active'] ? 'Да' : 'Нет'; ?>
                                    </td>
                                    <td class="edit-field" data-field="sort_order"><?php echo esc_html($bank['sort_order']); ?></td>
                                    <td>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=delete_bank&id=' . $bank['id']), 'delete_bank_' . $bank['id']); ?>"
                                            class="button button-danger delete-btn"
                                            onclick="return confirm('Вы уверены, что хотите удалить этот банк?')">Удалить</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">Нет доступных банков.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_banks > $per_page): ?>
                <!-- Пагинация -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php
                            printf(
                                '%s из %s',
                                esc_html(number_format_i18n(count($banks))),
                                esc_html(number_format_i18n($total_banks))
                            );
                            ?>
                        </span>
                        <?php
                        $pagination_links = paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                            'type'      => 'plain',
                        ]);

                        if ($pagination_links) {
                            echo wp_kses_post($pagination_links);
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>

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
                            'action': 'update_bank',
                            'id': id,
                            'nonce': '<?php echo wp_create_nonce('update_bank_nonce'); ?>'
                        };

                        row.find('.edit-input').each(function() {
                            var input = $(this);
                            var field = input.data('field');
                            data[field] = input.val();
                        });

                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                // Обновляем значения в ячейках
                                row.find('.edit-field[data-field="bank_code"]').text(response.data.bank_code);
                                row.find('.edit-field[data-field="name"]').text(response.data.name);
                                row.find('.edit-field[data-field="short_name"]').text(response.data.short_name);

                                var isActiveText = response.data.is_active == 1 ? 'Да' : 'Нет';
                                row.find('.edit-field[data-field="is_active"]').text(isActiveText);

                                row.find('.edit-field[data-field="sort_order"]').text(response.data.sort_order);

                                // Переключаем строку в режим просмотра без обновления значений
                                row.find('.save-btn, .cancel-btn').hide();
                                row.find('.edit-btn').show();

                                // Показываем сообщение об успешном обновлении
                                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Банк успешно обновлен.</p></div>');
                                setTimeout(function() {
                                    $('.notice-success').fadeOut().remove();
                                }, 3000);
                            } else {
                                alert('Ошибка при обновлении банка: ' + response.data.message);
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

                    // Обработка формы добавления банка
                    $('#add-bank').on('submit', function(e) {
                        e.preventDefault();

                        var formData = {
                            'action': 'add_bank',
                            'nonce': '<?php echo wp_create_nonce('add_bank_nonce'); ?>',
                            'bank_code': $('#bank_code').val(),
                            'name': $('#name').val(),
                            'short_name': $('#short_name').val(),
                            'is_active': $('#is_active').val(),
                            'sort_order': $('#sort_order').val()
                        };

                        $.post(ajaxurl, formData, function(response) {
                            if (response.success) {
                                // Перезагружаем страницу для отображения новой записи
                                var baseUrl = window.location.href.split('&message=')[0].split('&error=')[0];
                                window.location.href = baseUrl + '&message=added';
                            } else {
                                // Удаляем предыдущие уведомления об ошибках
                                $('.notice-error.bank-add-error').remove();
                                // Показываем ошибку как WordPress notice
                                var errorHtml = '<div class="notice notice-error is-dismissible bank-add-error"><p>' + response.data.message + '</p></div>';
                                $('#add-bank-form').before(errorHtml);
                                // Прокручиваем к сообщению об ошибке
                                $('html, body').animate({
                                    scrollTop: $('.bank-add-error').offset().top - 50
                                }, 300);
                            }
                        });
                    });
                });
            </script>
        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление банка
     */
    public function handle_update_bank(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'update_bank_nonce')) {
            wp_die('Неверный nonce.');
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав для выполнения этого действия.');
        }

        global $wpdb;

        $id = intval($_POST['id']);
        $bank_code = sanitize_text_field($_POST['bank_code']);
        $name = sanitize_text_field($_POST['name']);
        $short_name = sanitize_text_field($_POST['short_name']);
        $is_active = intval($_POST['is_active']);
        $sort_order = intval($_POST['sort_order']);

        // Валидация данных
        if (empty($bank_code) || empty($name)) {
            wp_send_json_error(['message' => 'Заполните все обязательные поля.']);
            return;
        }

        // Обновляем запись в базе данных
        $result = $wpdb->update(
            $this->table_name,
            [
                'bank_code' => $bank_code,
                'name' => $name,
                'short_name' => $short_name,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%d', '%d'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Ошибка при обновлении банка в базе данных.']);
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success([
            'id' => $id,
            'bank_code' => $bank_code,
            'name' => $name,
            'short_name' => $short_name,
            'is_active' => $is_active,
            'sort_order' => $sort_order
        ]);
    }

    /**
     * Обработка AJAX запроса на добавление банка
     */
    public function handle_add_bank(): void
    {
        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'add_bank_nonce')) {
            wp_die('Неверный nonce.');
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав для выполнения этого действия.');
        }

        global $wpdb;

        $bank_code = sanitize_text_field($_POST['bank_code']);
        $name = sanitize_text_field($_POST['name']);
        $short_name = sanitize_text_field($_POST['short_name']);
        $is_active = intval($_POST['is_active']);
        $sort_order = intval($_POST['sort_order']);

        // Валидация данных
        if (empty($bank_code) || empty($name)) {
            wp_send_json_error(['message' => 'Заполните все обязательные поля.']);
            return;
        }

        // Проверяем, существует ли уже банк с таким bank_code, name или short_name
        if (!empty($short_name)) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE bank_code = %s OR name = %s OR short_name = %s",
                    $bank_code,
                    $name,
                    $short_name
                )
            );
        } else {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE bank_code = %s OR name = %s",
                    $bank_code,
                    $name
                )
            );
        }

        if ($existing > 0) {
            wp_send_json_error(['message' => 'Такой банк уже добавлен, добавьте другой банк.']);
            return;
        }

        // Добавляем новую запись в базу данных
        $result = $wpdb->insert(
            $this->table_name,
            [
                'bank_code' => $bank_code,
                'name' => $name,
                'short_name' => $short_name,
                'is_active' => $is_active,
                'sort_order' => $sort_order
            ],
            ['%s', '%s', '%s', '%d', '%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Ошибка при добавлении банка в базе данных.']);
            return;
        }

        wp_send_json_success();
    }
}

// Инициализируем класс
$bank_management_admin = new Cashback_Bank_Management_Admin();

// Обработка удаления банка (через admin-post.php)
if (isset($_GET['action']) && $_GET['action'] === 'delete_bank' && isset($_GET['id'])) {
    if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_bank_' . intval($_GET['id']))) {
        wp_die('Неверный nonce.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Недостаточно прав для выполнения этого действия.');
    }

    global $wpdb;
    $id = intval($_GET['id']);

    $result = $wpdb->delete(
        $wpdb->prefix . 'cashback_banks',
        ['id' => $id],
        ['%d']
    );

    if ($result !== false) {
        wp_redirect(add_query_arg(['page' => 'cashback-banks', 'message' => 'deleted'], admin_url('admin.php')));
        exit;
    } else {
        wp_redirect(add_query_arg(['page' => 'cashback-banks', 'error' => 'delete_failed'], admin_url('admin.php')));
        exit;
    }
}
