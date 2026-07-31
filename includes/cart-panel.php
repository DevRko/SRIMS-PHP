<?php
/**
 * SRIMS - Requisition cart panel (Step 1 of New Requisition)
 * Shared between new.php's initial page render and cart-api.php's AJAX
 * responses, so both always produce identical markup — the cart updates
 * live via AJAX without a page reload, and this is the one place that
 * markup is defined.
 */
function renderCartPanelBody(array $cart, float $cartTotal): void
{
    ?>
    <h3 class="mb-4 flex items-center gap-2 text-[15px] font-semibold text-text-primary">
        <i data-lucide="shopping-cart" style="width:18px;height:18px"></i> Requisition Cart (<?= count($cart) ?> Items)
    </h3>
    <?php if (empty($cart)): ?>
    <div class="py-8 text-center">
        <i data-lucide="shopping-cart" class="mx-auto mb-3 text-text-muted" style="width:40px;height:40px"></i>
        <p class="text-[13px] text-text-secondary">Your cart is empty</p>
        <p class="text-[12px] text-text-muted">Add items from the catalog to get started</p>
    </div>
    <?php else: ?>
    <div class="space-y-3 max-h-[400px] overflow-y-auto">
        <?php foreach ($cart as $itemId => $c): ?>
        <div class="flex gap-3 rounded-lg border border-border p-3">
            <?= itemIconSvg($c['iconKey'], $itemId, 32) ?>
            <div class="flex-1 min-w-0">
                <div class="text-[13px] font-medium text-text-primary truncate"><?= e($c['itemName']) ?></div>
                <div class="text-[12px] text-text-secondary"><?= formatCurrency($c['unitPrice']) ?> / <?= e($c['unit']) ?></div>
                <div class="mt-2 flex items-center justify-between">
                    <div class="inline-flex items-center rounded-md border border-border">
                        <button type="button" class="cart-qty-minus flex h-7 w-7 items-center justify-center rounded-l-md border-r border-border text-text-secondary hover:bg-gray-50" data-item-id="<?= e($itemId) ?>"><i data-lucide="minus" style="width:12px;height:12px"></i></button>
                        <input type="number" inputmode="numeric" class="cart-qty-input w-14 h-7 border-none text-center text-[12px] focus:outline-none focus:ring-1 focus:ring-brand-primary" value="<?= (int) $c['quantity'] ?>" min="1" max="<?= (int) ($c['availableStock'] ?: 9999) ?>" data-item-id="<?= e($itemId) ?>">
                        <button type="button" class="cart-qty-plus flex h-7 w-7 items-center justify-center rounded-r-md border-l border-border text-text-secondary hover:bg-gray-50" data-item-id="<?= e($itemId) ?>"><i data-lucide="plus" style="width:12px;height:12px"></i></button>
                    </div>
                    <span class="text-[13px] font-semibold text-text-primary"><?= formatCurrency($c['unitPrice'] * $c['quantity']) ?></span>
                </div>
            </div>
            <button type="button" class="cart-remove-btn flex-shrink-0 self-start text-red-400 hover:text-red-600" data-item-id="<?= e($itemId) ?>"><i data-lucide="trash-2" style="width:16px;height:16px"></i></button>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4 space-y-2 border-t border-border pt-4">
        <div class="flex justify-between text-[13px]"><span class="text-text-secondary">Total Items:</span><span class="font-medium text-text-primary"><?= count($cart) ?></span></div>
        <div class="flex justify-between text-[14px]"><span class="font-medium text-text-primary">Total Amount:</span><span class="font-bold text-text-primary" id="cartTotalAmount"><?= formatCurrency($cartTotal) ?></span></div>
    </div>

    <div class="mt-3 flex items-start gap-2 rounded-md bg-tint-blue-bg p-2.5">
        <i data-lucide="info" class="mt-0.5 flex-shrink-0 text-tint-blue-icon" style="width:14px;height:14px"></i>
        <span class="text-[11px] text-tint-blue-icon">Your approver will review quantities and authorise issuance based on availability.</span>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="goto_step2">
        <button type="submit" class="mt-4 flex w-full items-center justify-center gap-2 rounded-button bg-brand-primary py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover transition-colors">
            Next: Requisition Details <i data-lucide="arrow-right" style="width:16px;height:16px"></i>
        </button>
    </form>
    <?php endif; ?>
    <?php
}