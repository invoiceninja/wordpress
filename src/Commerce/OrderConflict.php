<?php
/**
 * An order can't change the way an admin asked, right now.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

/**
 * The message is a translated sentence for the admin.
 */
final class OrderConflict extends \RuntimeException {}
