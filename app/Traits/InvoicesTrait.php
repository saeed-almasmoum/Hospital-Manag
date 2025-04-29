<?php

namespace App\Traits;

use App\Constants\FileTypeConstants;
use App\Events\Event;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Reports\AveragePurchasePriceReportController;
use App\Jobs\DeleteMessageWhatsApp;
use App\Models\BondsExchange;
use App\Models\BoxBalanceBond;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerMatching;
use App\Models\CustomsData;
use App\Models\CustomsDataFile;
use App\Models\DifferenceBond;
use App\Models\Exchange;
use App\Models\ExpensesBond;
use App\Models\FirstTermBond;
use App\Models\FirstTermInvoice;
use App\Models\GainDiscountBond;
use App\Models\GivenDiscountBond;
use App\Models\InOutStorehouse;
use App\Models\InternalOrder;
use App\Models\InvoicesExchange;
use App\Models\MoneyTransfer;
use App\Models\MoneyTransferBond;
use App\Models\NotificationsLog;
use App\Models\OpeningBalanceBond;
use App\Models\Order;
use App\Models\PartnersExpensesBond;
use App\Models\PayBond;
use App\Models\PersonalExpensesBond;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProjectOrder;
use App\Models\PurchaseInvoice;
use App\Models\ReceiptBond;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnSaleInvoice;
use App\Models\SaleInvoice;
use App\Models\StorehouseEquality;
use App\Models\SupplierMatching;
use App\Models\Tathbeet;
use App\Models\User;
use App\Models\UserMoneyTransferBond;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as RequestWhatsapp;
use Illuminate\Support\Facades\Storage;

trait InvoicesTrait
{
    use NotificationsTrait, ReportTrait;
    /**
     * التحقق من التثبيت من أجل فاتورة المبيع
     *
     * @param  \Illuminate\Http\Request  $request  الطلب الوارد
     * @param  string $operation  العملية المقدمة (إضافة، تحديث)
     * @return mixed
     */
    public function validateTathbeetForSale($request, $operation)
    {
        // اذا كانت الطلب يحتوي عل معرف تثبيت
        if (!empty($request->input('tathbeet_id'))) {
            // نقوم بإيجاد التثبيت من خلال معرفه
            $tathbeet = Tathbeet::find($request->input('tathbeet_id'));

            // إذا كان تاريخ الفاتورة قبل تاريخ التثبيت نرجع رسالة خطأ
            if ($request['date'] < $tathbeet->date) {
                return 'wrong_date';
            }

            // التحقق مما اذا كان الطلب يحتوي منتجات لا تنتمي لصنف التثبيت فيتم ارجاعهم برسالة خطأ
            // إنشاء مصفوفة للمنتجات التي لا تنتمي
            $productsDontBelong = [];
            foreach ($request->input('products') as $productData) {
                $product = Product::find($productData['product_id']);
                $cat_id = $tathbeet->category_id;
                $category = Category::find($cat_id);
                $childCategories = $category->allChildren()->pluck('id')->toArray();
                $childCategories[] = $cat_id;
                // إذا كان المنتج لا ينتمي لصنف التثبيت أو أحد أولاد صنفه
                if (!in_array($product->category_id, $childCategories)) {
                    // يتم إدخال اسم هذا المنتج في مصفوفة المنتجات التي لا تنتمي
                    $productsDontBelong[] = [
                        'name' => $product->name
                    ];
                }
            }
            // إذا كانت مصفوفة المنتجات التي لا تنتمي غير فارغة
            if (!empty($productsDontBelong)) {
                $productNames = collect($productsDontBelong)->pluck('name')->implode(', ');
                // إرجاع هذه المصفوفة برسالة خطأ
                return "products_dont_belong: $productNames";
            }

            //التحقق من نوع التثبيت
            switch ($tathbeet->type) {
                    // إذا كان نوعه كمية
                case 2:
                    // جلب كميات فواتير المبيع التي تنتمي لهذا التثبيت
                    $saleQty = $tathbeet->saleInvoices()->whereDate('date', '<=', $request['date'])
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->when($operation === 'update', function ($query) use ($request) {
                            $query->where('id', '!=', $request['sale_invoice_id']);
                        })
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // جلب كميات فواتير مرتجع المبيع التي تنتمي لهذا التثبيت
                    $returnSaleQty = $tathbeet->returnSaleInvoices()->whereDate('date', '<=', $request['date'])
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // فيكون الحد المسموح بيعه هو : كمية التثبيت ناقص كميات المبيع زائد كميات مرتجع المبيع
                    $limit = $tathbeet->quantity - $saleQty + $returnSaleQty;

                    // الحصول على مجموع الكميات من الطلب
                    $requestQty = collect($request->input('products'))->sum('quantity');

                    // إذا كان مجموع الكميات اكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestQty > $limit) {
                        return 'quantity_exceeded';
                    }

                    break;
            }
        }

        return true; // عدم إرجاع أي رسالة خطأ في حالة عدم إحتواء الطلب على معرف تثبيت
    }

    /**
     * يقوم بالتحقق من تثبيت الطلب لفواتير مرتجع المبيعات.
     *
     * @param  \Illuminate\Http\Request  $request  الطلب الوارد
     * @param  string                     $operation  العملية المقدمة (إضافة، تحديث)
     * @return mixed
     */
    public function validateTathbeetForReturnSale($request, $operation)
    {
        // إذا كان الطلب يحتوي على معرف تثبيت
        if (!empty($request->input('tathbeet_id'))) {
            // العثور على تثبيت بناءً على معرفه
            $tathbeet = Tathbeet::find($request->input('tathbeet_id'));

            // إذا كان تاريخ الفاتورة قبل تاريخ التثبيت نرجع رسالة خطأ
            if ($request['date'] < $tathbeet->date) {
                return 'wrong_date';
            }

            // التحقق مما إذا كانت المنتجات في الطلب تنتمي لصنف التثبيت
            $productsDontBelong = [];
            foreach ($request->input('products') as $productData) {
                $product = Product::find($productData['product_id']);
                $cat_id = $tathbeet->category_id;
                $category = Category::find($cat_id);
                $childCategories = $category->allChildren()->pluck('id')->toArray();
                $childCategories[] = $cat_id;
                // إذا كان المنتج لا ينتمي لصنف التثبيت أو أحد أولاد صنفه
                if (!in_array($product->category_id, $childCategories)) {
                    // يتم إضافة اسم هذا المنتج إلى قائمة المنتجات التي لا تنتمي
                    $productsDontBelong[] = [
                        'name' => $product->name
                    ];
                }
            }
            // إذا كانت قائمة المنتجات التي لا تنتمي غير فارغة نرجع رسالة خطأ
            if (!empty($productsDontBelong)) {
                $productNames = collect($productsDontBelong)->pluck('name')->implode(', ');
                return "products_dont_belong: $productNames";
            }

            // التحقق من نوع التثبيت
            switch ($tathbeet->type) {
                    // إذا كان نوع التثبيت كمية
                case 2:
                    // جلب كميات فواتير مرتجع المبيع التي تنتمي لهذا التثبيت
                    $returnSaleQty = $tathbeet->returnSaleInvoices()->whereDate('date', '<=', $request['date'])
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->when($operation === 'update', function ($query) use ($request) {
                            $query->where('id', '!=', $request['return_sale_invoice_id']);
                        })
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // جلب كميات فواتير المبيع التي تنتمي لهذا التثبيت
                    $saleQty = $tathbeet->saleInvoices()->whereDate('date', '<=', $request['date'])
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // الحد المسموح بيعه هو: كمية المبيع ناقص كميات مرتجع المبيع
                    $limit = $saleQty - $returnSaleQty;

                    // جلب مجموع الكميات من الطلب
                    $requestQty = collect($request->input('products'))->sum('quantity');

                    // إذا كان مجموع الكميات أكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestQty > $limit) {
                        return 'quantity_exceeded';
                    }

                    break;
            }
        }

        return true; // عدم إرجاع أي رسالة خطأ في حالة عدم إحتواء الطلب على معرف تثبيت
    }



    /**
     * يقوم بالتحقق من كميات المنتجات.
     *
     * @param  array       $products        بيانات المنتجات المطلوبة
     * @param  int|null    $storehouse_id   معرف المستودع (اختياري)
     * @param  string|null $date            تاريخ العملية (اختياري)
     * @param  string|null $operation       نوع العملية (اختياري)
     * @param  int|null    $invoice_id      معرف الفاتورة (اختياري)
     * @return array                        إرجاع المنتجات التي تجاوزت الكمية المتاحة
     */
    public function validateProductsQuantities($products, $storehouse_id = null, $date = null, $operation = null, $invoice_id = null)
    {
        // حساب الكميات لمعرفات المنتجات المتكررة وغير المتكررة
        $productsData = [];
        foreach ($products as $product) {
            $productId = $product['product_id'];
            $productQty = $product['quantity'];

            if (!isset($productsData[$productId])) {
                $productsData[$productId] = $productQty;
            } else {
                $productsData[$productId] += $productQty;
            }
        }

        // المنتجات التي تجاوزت الكمية المتاحة
        $exceededProducts = [];
        foreach ($productsData as $productId => $productQty) {
            $remainQty = $this->validateProductQty($productId, $storehouse_id, $date, $operation, $invoice_id);
            $remainQty = $remainQty['remainQty'];
            if ($productQty > $remainQty) {
                $productName = Product::find($productId)->name;
                $exceededProducts[] = [
                    'name' => $productName,
                    'required_quantity' => $productQty,
                    'available_quantity' => $remainQty,
                ];
            }
        }

        return $exceededProducts;
    }


    /**
     * يقوم بالتحقق من الكمية المتاحة للمنتج.
     *
     * @param  int         $product_id     معرف المنتج
     * @param  int|null    $storehouse_id  معرف المستودع (اختياري)
     * @param  string|null $date           تاريخ العملية (اختياري)
     * @param  string|null $operation      نوع العملية (اختياري)
     * @param  int|null    $invoice_id     معرف الفاتورة (اختياري)
     * @return int                         إرجاع الكمية المتاحة للمنتج
     */
    public function validateProductQty($product_id, $storehouse_id = null, $date = null, $operation = null, $invoice_id = null, $category_id = null)
    {
        // الكمية الواردة
        $inQty = 0;
        // الكمية الخارجة
        $outQty = 0;
        // حساب كمية المبيع و إضافتها للكمية الخارجة
        $outQty += SaleInvoice::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })->when($category_id != null, function ($query) use ($category_id) {

                $category = Category::find($category_id);
                $childCategories = $category->allChildren()->pluck('id')->toArray();
                $childCategories[] = $category_id;
                $query->whereHas('products', function ($subQuery) use ($childCategories) {
                    $subQuery->whereIn('category_id', $childCategories);
                });
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id);
            })
            ->when($operation === 'update', function ($query) use ($invoice_id) {
                $query->where('id', '!=', $invoice_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum('pivot.quantity');

        // حساب كمية التسوية الجردية السالبة و إضافتها للكمية الخارجة
        $negativeQty = StorehouseEquality::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })->when($category_id != null, function ($query) use ($category_id) {

            $category = Category::find($category_id);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $category_id;
            $query->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id)
                    ->where('storehouse_equality_products.quantity', '<', 0);
            })
            ->when($operation === 'update', function ($query) use ($invoice_id) {
                $query->where('id', '!=', $invoice_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id)
                        ->where('storehouse_equality_products.quantity', '<', 0);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum(function ($product) {
                return $product->pivot->quantity;
            });
        $negativeQty *= -1;
        $outQty += $negativeQty;

        // حساب كمية التسوية الجردية الموجبة و إضافتها للكمية الواردة
        $inQty += StorehouseEquality::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })->when($category_id != null, function ($query) use ($category_id) {

            $category = Category::find($category_id);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $category_id;
            $query->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id)
                    ->where('storehouse_equality_products.quantity', '>', 0);
            })
            ->when($operation === 'update', function ($query) use ($invoice_id) {
                $query->where('id', '!=', $invoice_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id)
                        ->where('storehouse_equality_products.quantity', '>', 0);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum(function ($product) {
                return $product->pivot->quantity;
            });

        // حساب كمية مرتجع المبيع و إضافتها للكمية الواردة
        $inQty += ReturnSaleInvoice::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })->when($category_id != null, function ($query) use ($category_id) {

            $category = Category::find($category_id);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $category_id;
            $query->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum('pivot.quantity');

        // حساب كمية الشراء و إضافتها للكمية الواردة
        $inQty += PurchaseInvoice::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })->when($category_id != null, function ($query) use ($category_id) {

            $category = Category::find($category_id);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $category_id;
            $query->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum('pivot.quantity');

        // حساب كمية بضاعة أول مدة و إضافتها للكمية الواردة
        $inQty += FirstTermInvoice::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })->when($category_id != null, function ($query) use ($category_id) {

            $category = Category::find($category_id);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $category_id;
            $query->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum('pivot.quantity');

        // حساب كمية مرتجع الشراء و إضافتها للكمية الخارجة
        $outQty += ReturnPurchaseInvoice::when($storehouse_id != null, function ($query) use ($storehouse_id) {
            $query->where('storehouse_id', $storehouse_id);
        })->when($category_id != null, function ($query) use ($category_id) {

            $category = Category::find($category_id);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $category_id;
            $query->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        })
            ->when($date != null, function ($query) use ($date) {
                $query->whereDate('date', '<=', $date);
            })
            ->whereHas('products', function ($query) use ($product_id) {
                $query->where('products.id', $product_id);
            })
            ->with([
                'products' => function ($query) use ($product_id) {
                    $query->where('products.id', $product_id);
                }
            ])
            ->get()
            ->pluck('products')
            ->flatten()
            ->sum('pivot.quantity');

        // فتكون الكمية المتبقة للمنتج هي كميته الواردة ناقص كميةته الخارجة
        $remainQty = $inQty - $outQty;

        $data = [
            'remainQty' => $remainQty,
            'inQty' => $inQty,
            'outQty' => $outQty,
        ];

        return $data;
    }

    /**
     * يقوم بالتحقق من مطابقة إجمالي صافي قلم فاتورة المحوسب مع قيمته التي تم إرسالها أثناء إنشاء او تعديل فاتورة.
     *
     * @param  array      $products               مصفوفة المنتجات
     * @param  float|null $percent_discount       حسم الفاتورة بالنسبة المئوية (اختياري)
     * @param  float|null $const_discount         حسم الفاتورة الثابت (اختياري)
     * @param  float      $requestTotalNetPrice   القيمة المرسلة لإجمالي صافي قلم الفاتورة
     * @return bool                               false إذا كانت القيمة المحسوبة متطابقة مع القيمة المطلوبة وإلا true يتم إرجاع
     */
    public function validateTotalNetPrice($products, $percent_discount, $const_discount, $requestTotalNetPrice)
    {
        $totalNetPrice = 0;

        // حساب إجمالي السعر الصافي لكل منتج وإضافة الخصومات
        foreach ($products as $product) {
            $productTotal = $product['price'] * $product['quantity'];
            $percent_discount_decimal = $product['percent_discount'] / 100;
            $totalNetPrice += $productTotal * (1 + $percent_discount_decimal);
        }

        // إضافة حسم الفاتورة بالنسبة المئوية إذا كان موجوداً
        if (!is_null($percent_discount)) {
            $totalNetPrice *= (1 + ($percent_discount / 100));
        }

        // إضافة حسم الفاتورة الثابت إذا كان موجوداً
        if (!is_null($const_discount)) {
            $totalNetPrice += $const_discount;
        }

        // التحقق مما إذا كان إإجمالي صافي قلم فاتورة المحسوب متطابقاً مع القيمة المطلوبة
        if (abs($totalNetPrice - $requestTotalNetPrice) > 0.0001) {
            return false;
        }

        return true;
    }


    /**
     * يتعامل مع عمليات تحويل الأموال المتعلقة بالفواتير المبيعات.
     *
     * @param  string       $operation     نوع العملية ('store' أو 'update')
     * @param  SaleInvoice  $saleInvoice   الفاتورة المبيعات
     * @param  array        $requestData   بيانات الطلب
     * @return void
     */
    public function handleMoneyTransfers($operation, $saleInvoice, $requestData)
    {
        switch ($operation) {
            case 'store':
                // إذا تم توفير 'money_transfers' في الطلب، قم بإنشاء سجلات MoneyTransfer
                if (!empty($requestData['money_transfers'])) {
                    foreach ($requestData['money_transfers'] as $element) {
                        MoneyTransfer::create([
                            'sale_invoice_id' => $saleInvoice->id,
                            'user_id' => $element['user_id'],
                            'amount' => $element['amount'],
                        ]);
                    }
                }
                break;
            case 'update':
                // إذا تم توفير 'money_transfers' في الطلب
                if (!empty($requestData['money_transfers'])) {
                    // إذا كانت للفاتورة المبيعات متحوّلات مالية مرتبطة بالفعل، قم بتحديثها
                    if ($saleInvoice->moneyTransfers->isNotEmpty()) {
                        foreach ($requestData['money_transfers'] as $element) {
                            $moneyTransfer = $saleInvoice->moneyTransfers->where('user_id', $element['user_id'])->first();
                            if ($moneyTransfer) {
                                $moneyTransfer->update([
                                    'amount' => $element['amount'],
                                ]);
                            } else {
                                // إنشاء سجلات MoneyTransfer جديدة
                                MoneyTransfer::create([
                                    'sale_invoice_id' => $saleInvoice->id,
                                    'user_id' => $element['user_id'],
                                    'amount' => $element['amount'],
                                ]);
                            }
                        }
                    } else {
                        // إذا لم تحتوي الفاتورة المبيعات على تحويلات مالية من قبل، قم بإنشاء جديدة
                        foreach ($requestData['money_transfers'] as $element) {
                            MoneyTransfer::create([
                                'sale_invoice_id' => $saleInvoice->id,
                                'user_id' => $element['user_id'],
                                'amount' => $element['amount'],
                            ]);
                        }
                    }
                } else {
                    // قم بحذف أي تحويلات مالية متصلة بالفاتورة المبيعات
                    $saleInvoice->moneyTransfers()->delete();
                }
                break;
        }
    }


    /**
     * معالجة عمليات التبادل
     *
     * @param \Illuminate\Http\Request $request طلب الوارد
     * @param int $model_id معرف النموذج
     * @param string $model_type نوع النموذج
     * @return void
     */
    public function handleExchanges($request, $model_id, $model_type)
    {
        // التحقق مما إذا كانت هناك عمليات تبادل موجودة في الطلب
        if ($request->has('exchanges')) {
            // الحصول على بيانات عمليات التبادل من الطلب
            $exchanges = $request->input('exchanges');

            // إنشاء اسم النموذج باستخدام نوع النموذج المحدد
            $modelClassName = "App\\Models\\$model_type";

            // إنشاء نموذج جديد بناء على اسم النموذج
            $model = new $modelClassName();

            // العثور على النموذج باستخدام معرف النموذج المعطى
            $model = $model->find($model_id);

            // حذف جميع عمليات التبادل المرتبطة بالنموذج
            $model->exchanges()->delete();

            // إنشاء عمليات التبادل الجديدة
            foreach ($exchanges as $exchange) {
                $model->exchanges()->create([
                    'currency_id' => $exchange['currency_id'],
                    'exchange' => $exchange['exchange'],
                ]);
            }
        }
    }


    /**
     * التحقق من بيانات التثبيت لسند الدفع
     * 
     * هذه الدالة تتحقق من بيانات التثبيت (Tathbeet) المتعلقة بسند الدفع (PayBond).
     * تتحقق من تاريخ التثبيت والنوع والعملة وتأكد أن قيمة السندات لا تتجاوز حد معين.
     *
     * @param \Illuminate\Http\Request $request الطلب الذي يحتوي على بيانات التثبيت وسند الدفع
     * @param string $operation العملية (إضافة أو تحديث)
     * @return string|bool تُعيد 'wrong_date' إذا كان التاريخ غير صحيح، 
     *                      أو 'value_exceeded' إذا كانت القيمة تتجاوز الحد المسموح به، 
     *                      أو true إذا كانت البيانات صحيحة
     */
    public function validateTathbeetForPayBond($request, $operation)
    {
        // تحقق مما إذا كانت معرف التثبيت موجودة في الطلب
        if (!empty($request->input('tathbeet_id'))) {
            // جلب بيانات التثبيت من قاعدة البيانات باستخدام المعرف
            $tathbeet = Tathbeet::find($request->input('tathbeet_id'));

            // تحقق من أن تاريخ الطلب ليس أقل من تاريخ التثبيت
            if ($request['date'] < $tathbeet->date) {
                return 'wrong_date';
            }

            // إذا كان نوع التثبيت 1
            if ($tathbeet->type == 1) {
                // جلب معرف العملة الافتراضية
                $default_currency_id = Currency::where('is_default', true)->first()->id;

                // إذا كانت عملة التثبيت مختلفة عن العملة الافتراضية
                if ($tathbeet->currency_id != $default_currency_id) {
                    // جلب معدل التبادل للتثبيت وتعديل قيمة التثبيت
                    $tathbeetEx = InvoicesExchange::where('invoicesable_id', $tathbeet->id)
                        ->where('currency_id', $tathbeet->currency_id)
                        ->where('invoicesable_type', 'Tathbeet')
                        ->value('exchange');
                    $tathbeet->value *= $tathbeetEx;
                }

                // جلب سندات الدفع المتعلقة بالتثبيت واستثناء السند الحالي في حالة التحديث
                $payBonds = PayBond::where('tathbeet_id', $tathbeet->id)
                    ->when($operation === 'update', function ($query) use ($request) {
                        $query->where('id', '!=', $request['pay_bond_id']);
                    })->get();

                // حساب مجموع مبالغ السندات
                $bondsAmounts = 0;
                foreach ($payBonds as $bond) {
                    if ($bond->currency_id != $default_currency_id) {
                        // جلب معدل التبادل للسند وتعديل مبلغ السند
                        $ex = BondsExchange::where('bondsable_id', $bond->id)
                            ->where('currency_id', $bond->currency_id)
                            ->where('bondsable_type', 'PayBond')
                            ->value('exchange');
                        $bond->amount *= $ex;
                    }
                    $bondsAmounts += $bond->amount;
                }

                // حساب مبلغ الطلب
                $requestAmount = $request['amount'];
                if ($request['currency_id'] != $default_currency_id) {
                    foreach ($request['exchanges'] as $exchange) {
                        if ($exchange['currency_id'] == $request['currency_id']) {
                            $exchangeValue = $exchange['exchange'];
                        }
                    }
                    $requestAmount *= $exchangeValue;
                }

                // حساب الحد الأقصى المسموح به
                $limit = $tathbeet->value - $bondsAmounts;

                // التحقق من أن مبلغ الطلب لا يتجاوز الحد المسموح به
                if ($requestAmount > $limit) {
                    return 'value_exceeded';
                }
            }
        }

        // إذا كانت البيانات صحيحة أو لم يتم تقديم معرف التثبيت
        return true;
    }



    /**
     * التحقق من تحويل الأموال
     * 
     * هذه الدالة تتحقق من تحويل الأموال المتعلقة بالفواتير والحدود المالية للمستخدم.
     * تتحقق من تاريخ الطلب، وحدود التحويل للمستخدم، وقيمة السندات المالية، والحد الأقصى لفاتورة البيع.
     *
     * @param \Illuminate\Http\Request $request الطلب الذي يحتوي على بيانات التحويل والفاتورة
     * @param string|null $operation العملية (إضافة أو تحديث)
     * @return string رسالة توضح إذا كان هناك خطأ في البيانات أو رسالة فارغة إذا كانت البيانات صحيحة
     */
    public function validateMoneyTransfer($request, $operation = null)
    {
        // جلب بيانات فاتورة البيع باستخدام معرف الفاتورة من الطلب
        $saleInvoice = SaleInvoice::find($request['sale_invoice_id']);
        $message = '';

        // تحقق من أن تاريخ الطلب ليس أقل من تاريخ فاتورة البيع
        if ($request['date'] < $saleInvoice->date) {
            $message = "تاريخ السند يجب أن يكون بعد أو مساوي لتاريخ فاتورة المبيع!";
            return $message;
        }

        // جلب حد التحويل المالي للمستخدم
        $userTransferMoneyLimit = MoneyTransfer::where('user_id', $request['user_id'])
            ->where('sale_invoice_id', $saleInvoice->id)
            ->value('amount');

        // حساب مجموع سندات تحويل الأموال للمستخدم مع استثناء السند الحالي في حالة التحديث
        $sumUserMoneyTransferBonds = UserMoneyTransferBond::where('user_id', $request['user_id'])
            ->where('sale_invoice_id', $saleInvoice->id)
            ->when($operation === 'update', function ($query) use ($request) {
                $query->where('id', '!=', $request['bond_id']);
            })
            ->sum('amount');


        // حساب الحد الأقصى المتبقي للمستخدم
        $limit = $userTransferMoneyLimit - $sumUserMoneyTransferBonds;

        // التحقق من أن مبلغ الطلب لا يتجاوز الحد المتبقي للمستخدم
        if ($request['amount'] > $limit) {
            $message = "مبلغ السند يتجاوز الحد المتبقي لتحويل الأموال للمستخدم بمقدار: " . ($request['amount'] - $limit);
            return $message;
        }

        // حساب مجموع قيم سندات تحويل الأموال المتعلقة بفاتورة البيع
        $moneyTransferBondsValues = UserMoneyTransferBond::where('sale_invoice_id', $saleInvoice->id)
            ->when($operation === 'update', function ($query) use ($request) {
                $query->where('id', '!=', $request['bond_id']);
            })
            ->sum('amount');

        // حساب الحد الأقصى المتبقي لفاتورة البيع
        $saleInvoiceLimit = $saleInvoice->total_net_price - $moneyTransferBondsValues;

        // التحقق من أن مبلغ الطلب لا يتجاوز الحد المتبقي لفاتورة البيع
        if ($request['amount'] > $saleInvoiceLimit) {
            $message = "مبلغ السند يتجاوز قيمة حوالة فاتورة المبيع المتبقية بمقدار: " . ($request['amount'] - $saleInvoiceLimit);
            return $message;
        }

        // إذا كانت البيانات صحيحة
        return $message;
    }


    /**
     * يقوم بالتحقق من تثبيت الطلب لفواتير مرتجع الشراء.
     *
     * @param  \Illuminate\Http\Request  $request  الطلب الوارد
     * @param  string $operation  العملية المقدمة (إضافة، تحديث)
     * @return mixed
     */
    public function validateTathbeetForReturnPurchase($request, $operation)
    {
        // إذا كان الطلب يحتوي على معرف تثبيت
        if (!empty($request->input('tathbeet_id'))) {
            // العثور على تثبيت بناءً على معرفه
            $tathbeet = Tathbeet::find($request->input('tathbeet_id'));

            // إذا كان تاريخ الفاتورة قبل تاريخ التثبيت نرجع رسالة خطأ
            if ($request['date'] < $tathbeet->date) {
                return 'wrong_date';
            }

            // التحقق مما إذا كانت المنتجات في الطلب تنتمي لصنف التثبيت
            $productsDontBelong = [];
            foreach ($request->input('products') as $productData) {
                $product = Product::find($productData['product_id']);
                $cat_id = $tathbeet->category_id;
                $category = Category::find($cat_id);
                $childCategories = $category->allChildren()->pluck('id')->toArray();
                $childCategories[] = $cat_id;
                // إذا كان المنتج لا ينتمي لصنف التثبيت أو أحد أولاد صنفه
                if (!in_array($product->category_id, $childCategories)) {
                    // يتم إضافة اسم هذا المنتج إلى قائمة المنتجات التي لا تنتمي
                    $productsDontBelong[] = [
                        'name' => $product->name
                    ];
                }
            }
            // إذا كانت قائمة المنتجات التي لا تنتمي غير فارغة نرجع رسالة خطأ
            if (!empty($productsDontBelong)) {
                $productNames = collect($productsDontBelong)->pluck('name')->implode(', ');
                return "products_dont_belong: $productNames";
            }

            // التحقق من نوع التثبيت
            switch ($tathbeet->type) {
                    // إذا كان نوع التثبيت كمية
                case 2:
                    // جلب كميات فواتير مرتجع الشراء التي تنتمي لهذا التثبيت
                    $returnPurchaseQty = $tathbeet->returnPurchaseInvoices()->whereDate('date', '<=', $request['date'])
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->when($operation === 'update', function ($query) use ($request) {
                            $query->where('id', '!=', $request['return_purchase_invoice_id']);
                        })
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // جلب كميات فواتير الشراء التي تنتمي لهذا التثبيت
                    $purchaseQty = $tathbeet->purchaseInvoices()->whereDate('date', '<=', $request['date'])
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // الحد المسموح به للمرتجع هو: كمية الشراء ناقص كميات مرتجع الشراء
                    $limit = $purchaseQty - $returnPurchaseQty;

                    // جلب مجموع الكميات من الطلب
                    $requestQty = collect($request->input('products'))->sum('quantity');

                    // إذا كان مجموع الكميات أكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestQty > $limit) {
                        return 'quantity_exceeded';
                    }

                    break;

                    // إذا كان نوع التثبيت قيمة
                case 1:
                    // جلب معرف العملة الافتراضية
                    $default_currency_id = Currency::where('is_default', true)->first()->id;

                    // جلب فواتير الشراء التي تنتمي لهذا التثبيت
                    $purchaseInvoices = $tathbeet->purchaseInvoices()->whereDate('date', '<=', $request['date'])->get();

                    // حساب إجمالي الشراء
                    $purchaseTotal = 0;
                    foreach ($purchaseInvoices as $invoice) {
                        if ($invoice->currency_id != $default_currency_id) {
                            // إذا كانت العملة ليست العملة الافتراضية، نقوم بتحويل السعر إلى العملة الافتراضية
                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where('currency_id', $invoice->currency_id)
                                ->where('invoicesable_type', 'PurchaseInvoice')
                                ->value('exchange');

                            $invoice->total_net_price *= $ex;
                        }

                        $purchaseTotal += $invoice->total_net_price;
                    }

                    // جلب فواتير مرتجع الشراء التي تنتمي لهذا التثبيت
                    $returnPurchaseInvoices = $tathbeet->returnPurchaseInvoices()->whereDate('date', '<=', $request['date'])
                        ->when($operation === 'update', function ($query) use ($request) {
                            $query->where('id', '!=', $request['return_purchase_invoice_id']);
                        })->get();

                    // حساب إجمالي مرتجع الشراء
                    $returnPurchaseTotal = 0;
                    foreach ($returnPurchaseInvoices as $invoice) {
                        if ($invoice->currency_id != $default_currency_id) {
                            // إذا كانت العملة ليست العملة الافتراضية، نقوم بتحويل السعر إلى العملة الافتراضية
                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where('currency_id', $invoice->currency_id)
                                ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                                ->value('exchange');

                            $invoice->total_net_price *= $ex;
                        }

                        $returnPurchaseTotal += $invoice->total_net_price;
                    }

                    // جلب إجمالي الطلب
                    $requestTotal = $request['total_net_price'];
                    // إذا كانت العملة ليست العملة الافتراضية، نقوم بتحويل السعر إلى العملة الافتراضية
                    if ($request['currency_id'] != $default_currency_id) {
                        foreach ($request['exchanges'] as $exchange) {
                            if ($exchange['currency_id'] == $request['currency_id']) {
                                $requestExchange = $exchange['exchange'];
                            }
                        }
                        $requestTotal *= $requestExchange;
                    }

                    // الحد المسموح به للقيمة هو: إجمالي الشراء ناقص إجمالي مرتجع الشراء
                    $limit = $purchaseTotal - $returnPurchaseTotal;

                    // إذا كانت القيمة المطلوبة أكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestTotal > $limit) {
                        return 'value_exceeded';
                    }

                    break;
            }
        }

        return true; // عدم إرجاع أي رسالة خطأ في حالة عدم إحتواء الطلب على معرف تثبيت
    }


    /**
     * يقوم بالتحقق من تثبيت الطلب لفواتير الشراء.
     *
     * @param  \Illuminate\Http\Request  $request  الطلب الوارد
     * @param  string $operation  العملية المقدمة (إضافة، تحديث)
     * @return mixed
     */
    public function validateTethbeetForPurchase($request, $operation)
    {
        // اذا كانت الطلب يحتوي عل معرف تثبيت
        if (!empty($request->input('tathbeet_id'))) {
            // نقوم بإيجاد التثبيت من خلال معرفه
            $tathbeet = Tathbeet::find($request->input('tathbeet_id'));

            // إذا كان تاريخ الفاتورة قبل تاريخ التثبيت نرجع رسالة خطأ
            if ($request['date'] < $tathbeet->date) {
                return 'wrong_date';
            }

            // التحقق مما اذا كان الطلب يحتوي منتجات لا تنتمي لصنف التثبيت فيتم ارجاعهم برسالة خطأ
            $productsDontBelong = [];
            foreach ($request->input('products') as $productData) {
                $product = Product::find($productData['product_id']);
                $cat_id = $tathbeet->category_id;
                $category = Category::find($cat_id);
                $childCategories = $category->allChildren()->pluck('id')->toArray();
                $childCategories[] = $cat_id;
                if (!in_array($product->category_id, $childCategories)) {
                    $productsDontBelong[] = [
                        'name' => $product->name
                    ];
                }
            }
            if (!empty($productsDontBelong)) {
                $productNames = collect($productsDontBelong)->pluck('name')->implode(', ');
                return "products_dont_belong: $productNames";
            }
            ////////////////
            //التحقق من نوع التثبيت
            switch ($tathbeet->type) {
                    // إذا كان نوعه كمية
                case 2:
                    // جلب كميات فواتير المبيع التي تنتمي لهذا التثبيت
                    $purchaseQty = $tathbeet->purchaseInvoices()->whereDate('date', '<=', $request['date'])
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->when($operation === 'update', function ($query) use ($request) {
                            $query->where('id', '!=', $request['purchase_invoice_id']);
                        })
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // جلب كميات فواتير مرتجع المبيع التي تنتمي لهذا التثبيت
                    $returnPurchaseQty = $tathbeet->returnPurchaseInvoices()->whereDate('date', '<=', $request['date'])
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // فيكون الحد المسموح بيعه هو : كمية التثبيت ناقص كميات المبيع زائد كميات مرتجع المبيع
                    $limit = $tathbeet->quantity - $purchaseQty + $returnPurchaseQty;

                    // الحصول على مجموع الكميات من الطلب
                    $requestQty = collect($request->input('products'))->sum('quantity');

                    // إذا كان مجموع الكميات اكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestQty > $limit) {
                        return 'quantity_exceeded'; // Quantity exceeded
                    }

                    break;

                    // أما إذا كان نوعه قيمة
                case 1:

                    // الحصول على معرف العملة الافتراضية للنظام
                    $default_currency_id = Currency::where('is_default', true)->first()->id;

                    // اذا كانت عملة التثبيت ليست الافتراضية
                    if ($tathbeet->currency_id != $default_currency_id) {
                        // نجلب سعر صرف عملة التثبيت
                        $exchangeValue = InvoicesExchange::where('invoicesable_id', $tathbeet->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('invoicesable_type', 'Tathbeet')
                            ->value('exchange');

                        // نحول قيمة التثبيت إلى العملة الافتراضية و ذلك بضربها بسعر صرفها
                        $tathbeet->value *= $exchangeValue;
                    }

                    // نجلب فواتير الشراء الخاصة بهذا التثبيت
                    $purchaseInvoices = $tathbeet->purchaseInvoices()->whereDate('date', '<=', $request['date'])
                        // إستثناء الفاتورة التي يتم تعديلها
                        ->when($operation === 'update', function ($query) use ($request) {
                            $query->where('id', '!=', $request['purchase_invoice_id']);
                        })->get();

                    // ننشئ هذا المتحول المبدئي من أجل زيادته بمبالغ الفواتير
                    $purchaseTotal = 0;

                    // نمر على جميع هذه الفواتير التي جلبناها فاتورة تلو الأخرى
                    foreach ($purchaseInvoices as $invoice) {
                        // إذا كانت عملة إحداهنّ ليست العملة الافتراضية للنظام
                        if ($invoice->currency_id != $default_currency_id) {
                            // نجلب سعر صرف عملتها
                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where(
                                    'currency_id',
                                    $invoice->currency_id
                                )
                                ->where('invoicesable_type', 'PurchaseInvoice')
                                ->value('exchange');
                            // نحول مبلغها بضربه بسعر صرفها
                            $invoice->total_net_price *= $ex;
                        }
                        // نزيد على المتحول المبدئي الخاص بفواتير الشراء مبلغ هذه الفاتورة و هكذا دواليك
                        $purchaseTotal += $invoice->total_net_price;
                    }
                    // نجلب فواتير مرتجع الشراء الخاصة بهذا التثبيت
                    $returnPurchaseInvoices = $tathbeet->returnPurchaseInvoices()->whereDate('date', '<=', $request['date'])->get();

                    // ننشئ هذا المتحول المبدئي من أجل زيادته بمبالغ الفواتير
                    $returnPurchaseTotal = 0;
                    // نمر على جميع هذه الفواتير التي جلبناها فاتورة تلو الأخرى
                    foreach ($returnPurchaseInvoices as $invoice) {
                        // إذا كانت عملة إحداهنّ ليست العملة الافتراضية للنظام
                        if ($invoice->currency_id != $default_currency_id) {
                            // نجلب سعر صرف عملتها
                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where(
                                    'currency_id',
                                    $invoice->currency_id
                                )
                                ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                                ->value('exchange');
                            // نحول مبلغها بضربه بسعر صرفها
                            $invoice->total_net_price *= $ex;
                        }

                        // نزيد على المتحول المبدئي الخاص بفواتير مرتجع الشراء مبلغ هذه الفاتورة و هكذا دواليك
                        $returnPurchaseTotal += $invoice->total_net_price;
                    }

                    // اسناد صافي قلم الفاتورة إلى متحول
                    $requestTotal = $request['total_net_price'];
                    // اذا كانت عملة الفاتورة ليست الافتراضية
                    if (
                        $request['currency_id'] != $default_currency_id
                    ) {
                        // نجلب سعر صرف عملتها
                        foreach ($request['exchanges'] as $exchange) {
                            if ($exchange['currency_id'] == $request['currency_id']) {
                                $requestExchange = $exchange['exchange'];
                            }
                        }
                        // نضرب صافي قلم الفاتورة بسعر الصرف لكي يتحول إلى العملة الافتراضية
                        $requestTotal *= $requestExchange;
                    }
                    // فيكون الحد المسموح هو قيمة التثبيت ناقص إجمالي المشتريات زائد إجمالي مرتجع المشتريات
                    $limit = $tathbeet->value - $purchaseTotal + $returnPurchaseTotal;

                    // فإن كان صافي القلم أكبر من الحد المسموح نقوم بإرجاع رسالة خظأ
                    if ($requestTotal > $limit) {
                        return 'value_exceeded';
                    }

                    break;
            }
            ////////////////
        }

        return true; // عدم إرجاع أي رسالة خطأ في حالة عدم إحتواء الطلب على معرف تثبيت
    }



    public function validatePurchaseForRestore($id)
    {
        $invoice = PurchaseInvoice::onlyTrashed()->find($id);

        if ($invoice->tathbeet_id) {
            $tathbeet = Tathbeet::find($invoice->tathbeet_id);
            ////////////////
            //التحقق من نوع التثبيت
            switch ($tathbeet->type) {
                    // إذا كان نوعه كمية
                case 2:
                    // جلب كميات فواتير المبيع التي تنتمي لهذا التثبيت
                    $purchaseQty = $tathbeet->purchaseInvoices()
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->where('id', '!=', $invoice->id)
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // جلب كميات فواتير مرتجع المشتريات التي تنتمي لهذا التثبيت
                    $returnPurchaseQty = $tathbeet->returnPurchaseInvoices()
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // فيكون الحد المسموح بيعه هو : كمية التثبيت ناقص كميات المبيع زائد كميات مرتجع المبيع
                    $limit = $tathbeet->quantity - $purchaseQty + $returnPurchaseQty;

                    // الحصول على مجموع الكميات من الطلب
                    $requestQty = collect($invoice->products)->sum('pivot.quantity');

                    // إذا كان مجموع الكميات اكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestQty > $limit) {
                        return false; // Quantity exceeded
                    }
                    break;

                    // أما إذا كان نوعه قيمة
                case 1:

                    // الحصول على معرف العملة الافتراضية للنظام
                    $default_currency_id = Currency::where('is_default', true)->first()->id;

                    // اذا كانت عملة التثبيت ليست الافتراضية
                    if ($tathbeet->currency_id != $default_currency_id) {
                        // نجلب سعر صرف عملة التثبيت
                        $exchangeValue = InvoicesExchange::where('invoicesable_id', $tathbeet->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('invoicesable_type', 'Tathbeet')
                            ->value('exchange');

                        // نحول قيمة التثبيت إلى العملة الافتراضية و ذلك بضربها بسعر صرفها
                        $tathbeet->value *= $exchangeValue;
                    }

                    // نجلب فواتير المبيع الخاصة بهذا التثبيت
                    $purchaseInvoices = $tathbeet->purchaseInvoices()
                        // إستثناء الفاتورة التي يتم تعديلها
                        ->where('id', '!=', $invoice->id)
                        ->get();

                    // ننشئ هذا المتحول المبدئي من أجل زيادته بمبالغ الفواتير
                    $purchaseTotal = 0;

                    // نمر على جميع هذه الفواتير التي جلبناها فاتورة تلو الأخرى
                    foreach ($purchaseInvoices as $invoice) {
                        // إذا كانت عملة إحداهنّ ليست العملة الافتراضية للنظام
                        if ($invoice->currency_id != $default_currency_id) {
                            // نجلب سعر صرف عملتها
                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where(
                                    'currency_id',
                                    $invoice->currency_id
                                )
                                ->where('invoicesable_type', 'PurchaseInvoice')
                                ->value('exchange');
                            // نحول مبلغها بضربه بسعر صرفها
                            $invoice->total_net_price *= $ex;
                        }
                        // نزيد على المتحول المبدئي الخاص بفواتير المبيع مبلغ هذه الفاتورة و هكذا دواليك
                        $purchaseTotal += $invoice->total_net_price;
                    }
                    // نجلب فواتير مرتجع المشتريات الخاصة بهذا التثبيت
                    $returnPurchaseInvoices = $tathbeet->returnPurchaseInvoices()
                        ->get();

                    // ننشئ هذا المتحول المبدئي من أجل زيادته بمبالغ الفواتير
                    $returnPurchaseTotal = 0;
                    // نمر على جميع هذه الفواتير التي جلبناها فاتورة تلو الأخرى
                    foreach ($returnPurchaseInvoices as $invoice) {
                        // إذا كانت عملة إحداهنّ ليست العملة الافتراضية للنظام
                        if ($invoice->currency_id != $default_currency_id) {
                            // نجلب سعر صرف عملتها
                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where(
                                    'currency_id',
                                    $invoice->currency_id
                                )
                                ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                                ->value('exchange');
                            // نحول مبلغها بضربه بسعر صرفها
                            $invoice->total_net_price *= $ex;
                        }

                        // نزيد على المتحول المبدئي الخاص بفواتير مرتجع المشتريات مبلغ هذه الفاتورة و هكذا دواليك
                        $returnPurchaseTotal += $invoice->total_net_price;
                    }

                    // اسناد صافي قلم الفاتورة إلى متحول
                    $requestTotal = $invoice->total_net_price;
                    // اذا كانت عملة الفاتورة ليست الافتراضية
                    if (
                        $invoice->currency_id != $default_currency_id
                    ) {
                        // نجلب سعر صرف عملتها
                        foreach ($invoice->exchanges as $exchange) {
                            if ($exchange['currency_id'] == $invoice->currency_id) {
                                $requestExchange = $exchange['exchange'];
                            }
                        }
                        // نضرب صافي قلم الفاتورة بسعر الصرف لكي يتحول إلى العملة الافتراضية
                        $requestTotal *= $requestExchange;
                    }
                    // فيكون الحد المسموح هو قيمة التثبيت ناقص إجمالي المبيعات زائد إجمالي مرتجع المبيعات
                    $limit = $tathbeet->value - $purchaseTotal + $returnPurchaseTotal;


                    // فإن كان صافي القلم أكبر من الحد المسموح نقوم بإرجاع رسالة خظأ
                    if ($requestTotal > $limit) {
                        return false;
                    }

                    break;
            }
            ////////////////
        }
        return true; // Quantity not exceeded or no tathbeet_id provided

    }

    public function validateReturnPurchaseForRestore($id)
    {
        $invoice = ReturnPurchaseInvoice::onlyTrashed()->find($id);

        if ($invoice->tathbeet_id) {
            $tathbeet = Tathbeet::find($invoice->tathbeet_id);

            switch ($tathbeet->type) {
                case 2:
                    $returnPurchaseQty = $tathbeet->returnPurchaseInvoices()
                        ->where('id', '!=', $invoice->id)
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');
                    // جلب كميات فواتير المبيع التي تنتمي لهذا التثبيت
                    $purchaseQty = $tathbeet->purchaseInvoices()
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');
                    $limit = $purchaseQty - $returnPurchaseQty;

                    $requestQty = collect($invoice->products)->sum('pivot.quantity');

                    if ($requestQty > $limit) {
                        return false;
                    }

                    break;

                case 1:
                    $default_currency_id = Currency::where('is_default', true)->first()->id;

                    $purchaseInvoices = $tathbeet->purchaseInvoices()
                        ->where('id', '!=', $invoice->id)
                        ->get();

                    $purchaseTotal = 0;
                    foreach ($purchaseInvoices as $invoice) {
                        if ($invoice->currency_id != $default_currency_id) {

                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where(
                                    'currency_id',
                                    $invoice->currency_id
                                )
                                ->where('invoicesable_type', 'PurchaseInvoice')
                                ->value('exchange');

                            $invoice->total_net_price *= $ex;
                        }

                        $purchaseTotal += $invoice->total_net_price;
                    }

                    $returnPurchaseInvoices = $tathbeet->returnPurchaseInvoices()
                        ->where('id', '!=', $invoice->id)
                        ->get();

                    $returnPurchaseTotal = 0;
                    foreach ($returnPurchaseInvoices as $invoice) {
                        if ($invoice->currency_id != $default_currency_id) {

                            $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                                ->where(
                                    'currency_id',
                                    $invoice->currency_id
                                )
                                ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                                ->value('exchange');

                            $invoice->total_net_price *= $ex;
                        }

                        $returnPurchaseTotal += $invoice->total_net_price;
                    }

                    $requestTotal = $invoice->total_net_price;
                    if ($invoice->currency_id != $default_currency_id) {
                        foreach ($invoice->exchanges as $exchange) {
                            if ($exchange['currency_id'] == $invoice->currency_id) {
                                $requestExchange = $exchange['exchange'];
                            }
                        }
                        $requestTotal *= $requestExchange;
                    }

                    $limit = $purchaseTotal - $returnPurchaseTotal;

                    if ($requestTotal > $limit) {
                        return false;
                    }

                    break;
            }
        }
        return true; // Quantity not exceeded or no tathbeet_id provided
    }

    public function validateSalesForRestore($id)
    {
        $invoice = SaleInvoice::onlyTrashed()->find($id);

        if ($invoice->tathbeet_id) {
            $tathbeet = Tathbeet::find($invoice->tathbeet_id);

            //التحقق من نوع التثبيت
            switch ($tathbeet->type) {
                    // إذا كان نوعه كمية
                case 2:
                    // جلب كميات فواتير المبيع التي تنتمي لهذا التثبيت
                    $saleQty = $tathbeet->saleInvoices()
                        // إستثناء كمية الفاتورة التي يتم تعديلها
                        ->where('id', '!=', $invoice->id)
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // جلب كميات فواتير مرتجع المبيع التي تنتمي لهذا التثبيت
                    $returnSaleQty = $tathbeet->returnSaleInvoices()
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // فيكون الحد المسموح بيعه هو : كمية التثبيت ناقص كميات المبيع زائد كميات مرتجع المبيع
                    $limit = $tathbeet->quantity - $saleQty + $returnSaleQty;

                    // الحصول على مجموع الكميات من الطلب
                    $requestQty = collect($invoice->products)->sum('pivot.quantity');

                    // إذا كان مجموع الكميات اكبر من الحد المسموح يتم إرجاع رسالة خطأ
                    if ($requestQty > $limit) {
                        return false;
                    }

                    break;
            }
        }
        return true; // Quantity not exceeded or no tathbeet_id provided

    }

    /**
     * التحقق من إعادة فواتير البيع للاسترجاع
     * 
     * هذه الدالة تتحقق من إمكانية استرجاع فاتورة مبيعات معادة بناءً على شروط معينة.
     * تتحقق من كمية المنتجات المعادة مقارنة بكمية المنتجات المباعة المرتبطة بالتثبيت (Tathbeet).
     *
     * @param int $id معرف الفاتورة المعادة
     * @return bool تُعيد true إذا كان الاسترجاع ممكنًا، أو false إذا كانت الكمية تتجاوز الحد المسموح به
     */
    public function validateReturnSalesForRestore($id)
    {
        // جلب الفاتورة المعادة من الفواتير المحذوفة
        $invoice = ReturnSaleInvoice::onlyTrashed()->find($id);

        // تحقق مما إذا كانت الفاتورة المعادة مرتبطة بتثبيت
        if ($invoice->tathbeet_id) {
            // جلب بيانات التثبيت
            $tathbeet = Tathbeet::find($invoice->tathbeet_id);
            switch ($tathbeet->type) {
                case 2:
                    // حساب كمية المنتجات المعادة في الفواتير الأخرى المرتبطة بالتثبيت
                    $returnSaleQty = $tathbeet->returnSaleInvoices()
                        ->where('id', '!=', $invoice->id)
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // حساب كمية المنتجات المباعة في الفواتير المرتبطة بالتثبيت
                    $saleQty = $tathbeet->saleInvoices()
                        ->with('products')
                        ->get()
                        ->pluck('products')
                        ->flatten()
                        ->sum('pivot.quantity');

                    // حساب الحد الأقصى المسموح به
                    $limit = $saleQty - $returnSaleQty;

                    // حساب كمية المنتجات في الفاتورة المعادة الحالية
                    $requestQty = collect($invoice->products)->sum('pivot.quantity');

                    // التحقق من أن كمية الطلب لا تتجاوز الحد المسموح به
                    if ($requestQty > $limit) {
                        return false;
                    }

                    break;
            }
        }

        // إذا كانت الكمية ضمن الحد المسموح به أو لم تكن الفاتورة مرتبطة بتثبيت
        return true;
    }



    /**
     * التحقق من إعادة سند الدفع للاسترجاع
     * 
     * هذه الدالة تتحقق من إمكانية استرجاع سند الدفع بناءً على شروط معينة.
     * تتحقق من قيمة السندات المرتبطة بالتثبيت (Tathbeet) والحدود المالية المرتبطة به.
     *
     * @param int $id معرف سند الدفع المحذوف
     * @return bool تُعيد true إذا كان الاسترجاع ممكنًا، أو false إذا كانت القيمة تتجاوز الحد المسموح به
     */
    public function validatePayBondForRestore($id)
    {
        // جلب سند الدفع من السندات المحذوفة
        $payBond = PayBond::onlyTrashed()->find($id);

        // تحقق مما إذا كان سند الدفع مرتبط بتثبيت
        if ($payBond->tathbeet_id) {
            // جلب بيانات التثبيت
            $tathbeet = Tathbeet::find($payBond->tathbeet_id);
            // جلب معرف العملة الافتراضية
            $default_currency_id = Currency::where('is_default', true)->first()->id;

            // إذا كانت عملة التثبيت مختلفة عن العملة الافتراضية
            if ($tathbeet->currency_id != $default_currency_id) {
                // جلب معدل التبادل للتثبيت وتعديل قيمة التثبيت
                $exchangeValue = InvoicesExchange::where('invoicesable_id', $tathbeet->id)
                    ->where('currency_id', $tathbeet->currency_id)
                    ->where('invoicesable_type', 'Tathbeet')
                    ->value('exchange');
                $tathbeet->value *= $exchangeValue;
            }

            // جلب سندات الدفع المتعلقة بالتثبيت واستثناء السند الحالي
            $payBonds = PayBond::where('tathbeet_id', $tathbeet->id)
                ->where('id', '!=', $payBond->id)
                ->get();
            $bondsAmounts = 0;

            // حساب مجموع مبالغ السندات
            foreach ($payBonds as $bond) {
                if ($bond->currency_id != $default_currency_id) {
                    // جلب معدل التبادل للسند وتعديل مبلغ السند
                    $ex = BondsExchange::where('bondsable_id', $bond->id)
                        ->where('currency_id', $bond->currency_id)
                        ->where('bondsable_type', 'PayBond')
                        ->value('exchange');
                    $bond->amount *= $ex;
                }
                $bondsAmounts += $bond->amount;
            }

            // حساب مبلغ الطلب
            $requestAmount = $payBond->amount;
            if ($payBond->currency_id != $default_currency_id) {
                foreach ($payBond->exchanges as $exchange) {
                    if ($exchange['currency_id'] == $payBond->currency_id) {
                        $exchangeValue = $exchange['exchange'];
                    }
                }
                $requestAmount *= $exchangeValue;
            }

            // حساب الحد الأقصى المسموح به
            $limit = $tathbeet->value - $bondsAmounts;

            // التحقق من أن مبلغ الطلب لا يتجاوز الحد المسموح به
            if ($requestAmount > $limit) {
                return false;
            }
        }

        // إذا كانت البيانات صحيحة أو لم يكن السند مرتبط بتثبيت
        return true;
    }


    /**
     * الحصول على البيانات المتبقية للتثبيت.
     *
     * @param int $tathbeetId
     * @param string|null $date
     * @return array
     */
    public function getTathbeetRemains($tathbeetId, $date = null)
    {
        // البحث عن التثبيت المحدد
        $tathbeet = Tathbeet::find($tathbeetId);
        // العملة الافتراضية
        $default_currency_id = Currency::where('is_default', true)->first()->id;
        $remainQuantities = [];
        $remainValues = [];
        $bondsLimit = 0;

        if ($tathbeet->type == 2) {
            // التثبيت من نوع الكميات
            // استعلامات لجلب كميات البيع والشراء وإرجاع الشراء
            $saleQtyQuery = $tathbeet->saleInvoices()->with('products');
            $returnSaleQtyQuery = $tathbeet->returnSaleInvoices()->with('products');
            $purchaseQtyQuery = $tathbeet->purchaseInvoices()->with('products');
            $returnPurchaseQtyQuery = $tathbeet->returnPurchaseInvoices()->with('products');
            // تطبيق تاريخ اختياري للبحث
            if ($date) {
                $saleQtyQuery->whereDate('date', '<=', $date);
                $returnSaleQtyQuery->whereDate('date', '<=', $date);
                $purchaseQtyQuery->whereDate('date', '<=', $date);
                $returnPurchaseQtyQuery->whereDate('date', '<=', $date);
            }
            // حساب الكميات
            $saleQty = $saleQtyQuery->get()->pluck('products')->flatten()->sum('pivot.quantity');
            $returnSaleQty = $returnSaleQtyQuery->get()->pluck('products')->flatten()->sum('pivot.quantity');
            $purchaseQty = $purchaseQtyQuery->get()->pluck('products')->flatten()->sum('pivot.quantity');
            $returnPurchaseQty = $returnPurchaseQtyQuery->get()->pluck('products')->flatten()->sum('pivot.quantity');

            // حساب الحدود المتبقية
            $saleLimit = $tathbeet->quantity - $saleQty + $returnSaleQty;
            $returnSaleLimit = $saleQty - $returnSaleQty;
            $purchaseLimit = $tathbeet->quantity - $purchaseQty + $returnPurchaseQty;
            $returnPurchaseLimit = $purchaseQty - $returnPurchaseQty;
            $remainQuantities[] = [
                'remain_sale_qty' => $saleLimit,
                'remain_return_sale_qty' => $returnSaleLimit,
                'remain_purchase_qty' => $purchaseLimit,
                'remain_return_purchase_qty' => $returnPurchaseLimit,
            ];
            // $tathbeet['remain_quantities'] = $remainQuantities;
        } else {

            $purchaseQuery = $tathbeet->purchaseInvoices();
            $returnPurchaseQuery = $tathbeet->returnPurchaseInvoices();

            if ($date) {
                $purchaseQuery->whereDate('date', '<=', $date);
                $returnPurchaseQuery->whereDate('date', '<=', $date);
            }

            $purchases = $purchaseQuery->get();
            $purchaseTotal = 0;
            foreach ($purchases as $invoice) {
                if ($invoice->currency_id != $tathbeet->currency_id) {
                    if ($invoice->currency_id == $default_currency_id) {
                        $tathbeetEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('invoicesable_type', 'PurchaseInvoice')
                            ->value('exchange');
                        $invoice->total_net_price /= $tathbeetEx;
                    } elseif ($tathbeet->currency_id == $default_currency_id) {
                        $invoiceEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                            ->where('currency_id', $invoice->currency_id)
                            ->where('invoicesable_type', 'PurchaseInvoice')
                            ->value('exchange');
                        $invoice->total_net_price *= $invoiceEx;
                    } else {
                        $invoiceEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                            ->where('currency_id', $invoice->currency_id)
                            ->where('invoicesable_type', 'PurchaseInvoice')
                            ->value('exchange');

                        $tathbeetEx = InvoicesExchange::where('invoicesable_id', $tathbeet->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('invoicesable_type', 'Tathbeet')
                            ->value('exchange');

                        ($invoice->total_net_price *= $invoiceEx) / $tathbeetEx;
                    }
                }
                $purchaseTotal += $invoice->total_net_price;
            }


            $returnPurchases = $returnPurchaseQuery->get();
            $returnPurchaseTotal = 0;
            foreach ($returnPurchases as $invoice) {
                if ($invoice->currency_id != $tathbeet->currency_id) {
                    if ($invoice->currency_id == $default_currency_id) {
                        $tathbeetEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                            ->value('exchange');
                        $invoice->total_net_price /= $tathbeetEx;
                    } elseif ($tathbeet->currency_id == $default_currency_id) {
                        $invoiceEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                            ->where('currency_id', $invoice->currency_id)
                            ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                            ->value('exchange');
                        $invoice->total_net_price *= $invoiceEx;
                    } else {
                        $invoiceEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                            ->where('currency_id', $invoice->currency_id)
                            ->where('invoicesable_type', 'ReturnPurchaseInvoice')
                            ->value('exchange');

                        $tathbeetEx = InvoicesExchange::where('invoicesable_id', $tathbeet->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('invoicesable_type', 'Tathbeet')
                            ->value('exchange');

                        ($invoice->total_net_price *= $invoiceEx) / $tathbeetEx;
                    }
                }
                $returnPurchaseTotal += $invoice->total_net_price;
            }


            $purchaseLimit = $tathbeet->value - $purchaseTotal + $returnPurchaseTotal;
            $returnPurchaseLimit = $purchaseTotal - $returnPurchaseTotal;
            $remainValues[] = [
                'remain_purchase_value' => $purchaseLimit,
                'remain_return_purchase_value' => $returnPurchaseLimit,
            ];
            // $tathbeet['remain_values'] = $remainValues;



            $payBonds = $tathbeet->payBonds()->get();
            $bondsAmounts = 0;
            foreach ($payBonds as $bond) {
                if ($bond->currency_id != $tathbeet->currency_id) {
                    if ($bond->currency_id == $default_currency_id) {
                        $tathbeetEx = BondsExchange::where('bondsable_id', $bond->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('bondsable_type', 'PayBond')
                            ->value('exchange');
                        $bond->amount /= $tathbeetEx;
                    } elseif ($tathbeet->currency_id == $default_currency_id) {
                        $bondsEx = BondsExchange::where('bondsable_id', $bond->id)
                            ->where('currency_id', $bond->currency_id)
                            ->where('bondsable_type', 'PayBond')
                            ->value('exchange');
                        $bond->amount *= $bondsEx;
                    } else {
                        $bondsEx = BondsExchange::where('bondsable_id', $bond->id)
                            ->where('currency_id', $bond->currency_id)
                            ->where('bondsable_type', 'PayBond')
                            ->value('exchange');

                        $tathbeetEx = BondsExchange::where('bondsable_id', $bond->id)
                            ->where('currency_id', $tathbeet->currency_id)
                            ->where('bondsable_type', 'PayBond')
                            ->value('exchange');

                        ($bond->amount *= $bondsEx) / $tathbeetEx;
                    }
                }
                $bondsAmounts += $bond->amount;
            }

            $bondsLimit = $tathbeet->value - $bondsAmounts;
        }

        // $tathbeet['remain_pay_bonds_value'] = $bondsLimit;
        return ['remain_quantities' => $remainQuantities, 'remain_values' => $remainValues, 'remain_pay_bonds_value' => $bondsLimit];
    }

    /**
     * تقوم هذه الدالة بمعالجة عملية تحويل عنصر إلى فاتورة بيع.
     *
     * @param \App\Models\SaleInvoice $saleInvoice الفاتورة الخاصة بالبيع.
     * @param string $convertibleType نوع العنصر القابل للتحويل (مثل Order، PurchaseInvoice، إلخ).
     * @param int $convertibleId معرف العنصر القابل للتحويل.
     * @return bool|string
     */
    public function handleConvertedItemToSale($saleInvoice, $convertibleType, $convertibleId)
    {
        // التحقق من وجود نوع العنصر القابل للتحويل
        if (empty($convertibleType)) {
            return true; // إعادة القيمة true في حالة عدم وجود نوع العنصر
        }

        // استرجاع اسم الفئة المحولة إلى النموذج بناءً على نوعه
        $convertibleClassName = "App\\Models\\$convertibleType";
        // dd($convertibleType);
        // إنشاء النموذج بشكل ابتدائي بناءً على اسم الفئة
        $convertibleModel = new $convertibleClassName();

        // البحث عن العنصر المحول بناءً على معرفه
        $convertibleModel = $convertibleModel->when(
            $convertibleType == 'PurchaseInvoice',
            function ($q) {
                $q->with('convertedFrom');
            }
        )
            ->find($convertibleId);

        // اذا كان العنصر غير موجود, نرجع رسالة خطأ
        if (!$convertibleModel) {
            return "model_does_not_exist";
        }

        // التحقق من أن تاريخ الفاتورة أقدم من تاريخ العنصر المحول
        if ($saleInvoice->date < $convertibleModel->date) {
            return 'wrong_date'; // إعادة رسالة خطأ إذا كانت تاريخ الفاتورة أقدم من تاريخ العنصر المحول
        }

        // إذا كان العنصر محول بالفعل لفاتورة مبيع من قبل, إرجاع رسالة خطأ
        if (!empty($convertibleModel->convertedToSale)) {
            return 'already_converted';
        }

        // إنشاء سجل في جدول ConvertedToSale وربطه بالفاتورة الخاصة بالبيع
        $convertibleModel->convertedToSale()->create([
            'sale_invoice_id' => $saleInvoice->id,
        ]);


        // معالجة الإشعارات بناءً على نوع العنصر المحول
        $convertibleModel['sale_invoice_code'] = $saleInvoice->code;
        switch ($convertibleType) {
            case 'PurchaseInvoice':
                $this->handlePurchaseNotifications($convertibleModel, 'convert-to-sale');
                $purchaseInvoice = $convertibleModel;
                if ($purchaseInvoice->convertedFrom && $purchaseInvoice->convertedFrom->convertible_type === 'Order') {
                    $order = Order::find($purchaseInvoice->convertedFrom->convertible_id);
                    $this->handleOrderTracking($order, 'convert-to-sale', $purchaseInvoice->id, $saleInvoice->id);
                }
                break;
            case 'InternalOrder':
                $internalOrder = InternalOrder::find($convertibleId);
                if (!$internalOrder->prepared) {
                    return "internal_order_not_prepared"; // إعادة رسالة خطأ إذا لم يتم تجهيز الطلب الداخلي
                }
                $this->handleInternalOrderNotifications($convertibleModel, 'convert-to-sale');
                $this->handleInternalOrderTracking($convertibleModel, 'convert-to-sale', $saleInvoice->id);
                break;
            case 'OfferPrice':
                $this->handleOfferPriceNotifications($convertibleModel, 'convert-to-sale');
                break;
            case 'InOutStorehouse':
                $outStorehouse = InOutStorehouse::find($convertibleId);
                if ($outStorehouse->type == 1) {
                    return "in_storehouse_not_allowed";
                }
                if (!$outStorehouse->checked) {
                    return "out_storehouse_not_checked"; // إعادة رسالة خطأ إذا لم يتم تدقيق إخراج المستودع
                }
                $this->handleInOutStorehouseNotifications($convertibleModel, 'convert-to-sale');
                break;
            default:
                break;
        }

        return true; // إعادة true إذا تمت جميع الفحوص بنجاح
    }



    /**
     * تقوم هذه الدالة بمعالجة عملية تحويل عنصر إلى فاتورة شراء.
     *
     * @param \App\Models\PurchaseInvoice $purchaseInvoice الفاتورة الخاصة بالشراء.
     * @param string $convertibleType نوع العنصر القابل للتحويل (مثل Order، InStorehouse )
     * @param int $convertibleId معرف العنصر القابل للتحويل.
     * @return bool|string
     */
    public function handleConvertedItemToPurchase($purchaseInvoice, $convertibleType, $convertibleId)
    {
        // التحقق من وجود نوع العنصر القابل للتحويل
        if (empty($convertibleType)) {
            return true; // إعادة القيمة true في حالة عدم وجود نوع العنصر
        }

        // استرجاع اسم الفئة المحولة إلى النموذج بناءً على نوعه
        $convertibleClassName = "App\\Models\\$convertibleType";

        // إنشاء النموذج بشكل ابتدائي بناءً على اسم الفئة
        $convertibleModel = new $convertibleClassName();

        // البحث عن العنصر المحول بناءً على معرفه
        $convertibleModel = $convertibleModel->find($convertibleId);

        // اذا كان العنصر غير موجود, نرجع رسالة خطأ
        if (!$convertibleModel) {
            return "model_does_not_exist";
        }

        // التحقق من أن تاريخ الفاتورة أقدم من تاريخ العنصر المحول
        if ($purchaseInvoice->date < $convertibleModel->date) {
            return 'wrong_date'; // إعادة رسالة خطأ إذا كانت تاريخ الفاتورة أقدم من تاريخ العنصر المحول
        }

        // إذا كان العنصر محول بالفعل لفاتورة شراء من قبل, إرجاع رسالة خطأ
        if (!empty($convertibleModel->convertedToPurchase)) {
            return 'already_converted';
        }

        // إنشاء سجل في جدول ConvertedToPurchase وربطه بالفاتورة الخاصة بالبيع
        $convertibleModel->convertedToPurchase()->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
        ]);


        // معالجة الإشعارات بناءً على نوع العنصر المحول

        $convertibleModel['purchase_invoice_code'] = $purchaseInvoice->code;
        switch ($convertibleType) {
            case 'Order':
                // $order = Order::find($convertibleId);
                // if (!$order->checked) {
                //     return "order_not_checked"; // إعادة رسالة خطأ إذا لم يتم تدقيق الطلب
                // }
                $this->handleOrderNotifications($convertibleModel, 'convert-to-purchase');
                $orderConvertedFromId = null;
                if (!empty($convertibleModel->convertedFrom)) {
                    $orderConvertedFromId = $convertibleModel->convertedFrom()->pluck('convertible_id')->first();
                }
                $this->handleOrderTracking($convertibleModel, 'convert-to-purchase', $purchaseInvoice->id, null, null, $orderConvertedFromId);
                break;
            case 'InOutStorehouse':
                $inStorehouse = InOutStorehouse::find($convertibleId);
                if ($inStorehouse->type == 2) {
                    return "out_storehouse_not_allowed";
                }
                if (!$inStorehouse->checked) {
                    return "in_storehouse_not_checked"; // إعادة رسالة خطأ إذا لم يتم تدقيق إدخال المستودع
                }
                $this->handleInOutStorehouseNotifications($convertibleModel, 'convert-to-purchase');
                break;
            default:
                break;
        }

        return true; // إعادة true إذا تمت جميع الفحوص بنجاح
    }

    public function handleConvertedItemToInStorehouse($inStorehouse, $convertibleType, $convertibleId)
    {
        if (empty($convertibleType)) {
            return true;
        }

        $convertibleClassName = "App\\Models\\$convertibleType";

        $convertibleModel = new $convertibleClassName();

        $convertibleModel = $convertibleModel->find($convertibleId);

        if (!$convertibleModel) {
            return "model_does_not_exist";
        }

        if ($inStorehouse->date < $convertibleModel->date) {
            return 'wrong_date';
        }

        if (!empty($convertibleModel->convertedToInStorehouse)) {
            return 'already_converted';
        }

        $convertibleModel->convertedToInStorehouse()->create([
            'in_out_storehouse_id' => $inStorehouse->id,
        ]);

        $convertibleModel['in_storehouse_code'] = $inStorehouse->code;
        switch ($convertibleType) {
            case 'Order':
                $convertibleModel->delivery()->create([
                    'type' => 3,
                    'delivered_by' => auth()->user()->id
                ]);
                $this->handleOrderNotifications($convertibleModel, 'deliver');
                $this->handleOrderNotifications($convertibleModel, 'convert-to-in-storehouse');
                $orderConvertedFromId = null;
                if (!empty($convertibleModel->convertedFrom)) {
                    $orderConvertedFromId = $convertibleModel->convertedFrom()->pluck('convertible_id')->first();
                }
                $this->handleOrderTracking($convertibleModel, 'convert-to-in-storehouse', null, null, $inStorehouse->id, $orderConvertedFromId);
                break;
            default:
                break;
        }

        return true;
    }

    /**
     * تقوم هذه الدالة بمعالجة عملية تحويل عنصر إلى طلب توريد مواد.
     *
     * @param \App\Models\Order $order طلب توريد المواد.
     * @param string $convertibleType نوع العنصر القابل للتحويل 
     * @param int $convertibleId معرف العنصر القابل للتحويل.
     * @return bool|string
     */
    public function handleConvertedItemToOrder($order, $convertibleType, $convertibleId)
    {
        // التحقق من وجود نوع العنصر القابل للتحويل
        if (empty($convertibleType)) {
            return true; // إعادة القيمة true في حالة عدم وجود نوع العنصر
        }

        // استرجاع اسم الفئة المحولة إلى النموذج بناءً على نوعه
        $convertibleClassName = "App\\Models\\$convertibleType";

        // إنشاء النموذج بشكل ابتدائي بناءً على اسم الفئة
        $convertibleModel = new $convertibleClassName();

        // البحث عن العنصر المحول بناءً على معرفه
        $convertibleModel = $convertibleModel->find($convertibleId);

        // اذا كان العنصر غير موجود, نرجع رسالة خطأ
        if (!$convertibleModel) {
            return "model_does_not_exist";
        }

        // التحقق من أن تاريخ الطلب أقدم من تاريخ العنصر المحول
        if ($order->date < $convertibleModel->date) {
            return 'wrong_date'; // إعادة رسالة خطأ إذا كانت تاريخ الطلب أقدم من تاريخ العنصر المحول
        }

        // إذا كان العنصر محول بالفعل لطلب توريد مواد من قبل, إرجاع رسالة خطأ
        if (!empty($convertibleModel->convertedToOrder)) {
            return 'already_converted';
        }

        // إنشاء سجل في جدول ConvertedToOrder وربطه بطلب توريد المواد
        $convertibleModel->ConvertedToOrder()->create([
            'order_id' => $order->id,
        ]);


        // معالجة الإشعارات بناءً على نوع العنصر المحول

        $convertibleModel['order_code'] = $order->code;
        switch ($convertibleType) {
            case 'StorehouseModification':
                $this->handleOrderTracking($order, 'convert-from-storehouse-modification', null, null, null, $convertibleId);
                break;
            default:
                break;
        }

        return true; // إعادة true إذا تمت جميع الفحوص بنجاح
    }

    public function handleConvertedItemToOffer($offerPrice, $convertibleType, $convertibleId)
    {
        // التحقق من وجود نوع العنصر القابل للتحويل
        if (empty($convertibleType)) {
            return true; // إعادة القيمة true في حالة عدم وجود نوع العنصر
        }

        // استرجاع اسم الفئة المحولة إلى النموذج بناءً على نوعه
        $convertibleClassName = "App\\Models\\$convertibleType";

        // إنشاء النموذج بشكل ابتدائي بناءً على اسم الفئة
        $convertibleModel = new $convertibleClassName();

        // البحث عن العنصر المحول بناءً على معرفه
        $convertibleModel = $convertibleModel->find($convertibleId);

        // اذا كان العنصر غير موجود, نرجع رسالة خطأ
        if (!$convertibleModel) {
            return "model_does_not_exist";
        }

        // التحقق من أن تاريخ الفاتورة أقدم من تاريخ العنصر المحول
        if ($offerPrice->date < $convertibleModel->date) {
            return 'wrong_date'; // إعادة رسالة خطأ إذا كانت تاريخ الفاتورة أقدم من تاريخ العنصر المحول
        }

        // إذا كان العنصر محول بالفعل لفاتورة شراء من قبل, إرجاع رسالة خطأ
        if (!empty($convertibleModel->convertedToOffer)) {
            return 'already_converted';
        }

        // إنشاء سجل في جدول ConvertedToPurchase وربطه بالفاتورة الخاصة بالبيع
        $convertibleModel->convertedToOffer()->create([
            'offer_price_id' => $offerPrice->id,
        ]);


        // معالجة الإشعارات بناءً على نوع العنصر المحول

        $convertibleModel['offer_price_code'] = $offerPrice->code;
        switch ($convertibleType) {
            case 'ProjectOrder':
                $projectOrder = ProjectOrder::find($convertibleId);
                $projectOrder->pricingLog()->create([
                    'priced_by' => auth()->user()->id,
                ]);

                $log = NotificationsLog::where('notifiable_type', 'ProjectOrder')
                    ->where('notifiable_id', $projectOrder->id)
                    ->where('message', 'like', 'يجب تسعير طلب توريد مواد مشروع%')
                    ->first();

                // إذا وجد السجل، تحديث حالة المستخدم ليصبح متاحًا
                if ($log) {
                    User::where('id', $log->user_id)->update(['available' => true]);
                }
                $this->handleProjectOrderNotifications($convertibleModel, 'convert-to-offer');
                break;
            default:
                break;
        }

        return true; // إعادة true إذا تمت جميع الفحوص بنجاح
    }

    /**
     * تتبع الطلبات وإرسال الأحداث المناسبة بناءً على العمليات.
     *
     * @param \App\Models\Order $order الطلب الذي يجب تتبعه.
     * @param string $operation العملية التي تم تنفيذها على الطلب (فحص، تحويل إلى شراء، تحويل إلى بيع).
     * @param mixed $purchaseId (اختياري) معرف الفاتورة في حالة التحويل إلى شراء.
     * @param mixed $saleId (اختياري) معرف الفاتورة في حالة التحويل إلى بيع.
     * @param mixed $inStorehouseId (اختياري) معرف إدخال المستودع في حالة التحويل منه.
     * @param mixed $storehouseModificationId (اختياري) معرف ترميم المستودع في حالة التحويل منه.
     * @return void
     */
    public function handleOrderTracking($order, $operation, $purchaseId = null, $saleId = null, $inStorehouseId = null, $storehouseModificationId = null)
    {
        // تحديد نوع الحدث بناءً على العملية.
        switch ($operation) {
            case 'check':
                $eventType = 'OrderChecked';
                break;
            case 'convert-to-purchase':
                $eventType = 'OrderConvertedToPurchase';
                break;
            case 'convert-to-sale':
                $eventType = 'OrderConvertedToSale';
                break;
            case 'convert-from-storehouse-modification':
                $eventType = 'OrderConvertedFromStorehouseModification';
                break;
            case 'convert-to-in-storehouse':
                $eventType = 'OrderConvertedFromStorehouseModification';
                break;
        }

        $deliveryArr = [];
        if ($order->delivery) {
            foreach ($order->delivery as $delivery) {
                $deliveryArr[] = $delivery->type;
            }
        }
        // تحضير بيانات الحدث.
        $data = [
            'id' => $order->id,
            'checked' => $order->checked,
            'with_customer' => $order->customer_id ? true : false,
            'with_shipping_office' => $order->shipping_office ? true : false,
            'delivery_type' => $deliveryArr,
            'convertedToInStorehouse' => $inStorehouseId,
            'convertedToPurchase' => $purchaseId,
            'convertedToSale' => $saleId,
            'convertedFromStorehouseModification' => $storehouseModificationId,
        ];

        // إرسال الحدث.
        event(new Event($eventType, 'OrderTracking', $data));
    }


    /**
     * تتبع طلبات التجهيز الداخلي وإرسال الأحداث المناسبة بناءً على العمليات.
     *
     * @param \App\Models\InternalOrder $internalOrder طلب التجهيز الداخلي الذي يجب تتبعه.
     * @param string $operation العملية التي تم تنفيذها على طلب التجهيز الداخلي (التحضير، تحويل إلى بيع).
     * @param mixed $saleId (اختياري) معرف الفاتورة في حالة التحويل إلى بيع.
     * @return void
     */
    public function handleInternalOrderTracking($internalOrder, $operation, $saleId = null)
    {
        // تحديد نوع الحدث بناءً على العملية.
        switch ($operation) {
            case 'prepare':
                $eventType = 'InternalOrderPrepared';
                break;
            case 'convert-to-sale':
                $eventType = 'InternalOrderConvertedToSale';
                break;
        }

        // تحضير بيانات الحدث.
        $data = [
            'id' => $internalOrder->id,
            'prepared' => $internalOrder->prepared,
            'convertedToSale' => $saleId
        ];

        // إرسال الحدث.
        event(new Event($eventType, 'InternalOrderTracking', $data));
    }



    /**
     * التعامل مع متوسط السعر عند الحذف أو الاسترجاع
     * 
     * هذه الدالة تقوم بتحديث متوسط سعر الشراء لكل منتج عند حذف أو استرجاع فواتيره.
     * تستدعي تقرير متوسط سعر الشراء وتحدث متوسط السعر لكل منتج.
     *
     * @param \Illuminate\Database\Eloquent\Collection $products مجموعة المنتجات التي سيتم تحديث متوسط سعرها
     * @return void
     */
    public function handleAvgPriceOnDeleteOrRestore($products)
    {
        foreach ($products as $product) {
            // إنشاء كائن من وحدة التحكم بتقرير متوسط سعر الشراء
            $averagePriceController = new AveragePurchasePriceReportController();

            // إنشاء طلب جديد وإعداد معرف المنتج في الطلب
            $request = new Request();
            $request['product_id'] = $product->id;

            // استدعاء تقرير متوسط سعر الشراء
            $data = $averagePriceController->report($request);

            // الحصول على البيانات الأصلية من التقرير
            $originalData = $data->getOriginalContent();
            $averagePurchasePrice = $originalData['data']['average_price'];

            // تحديث متوسط سعر الشراء للمنتج
            $product->price()->where('price_type_id', 3)->update(['value' => $averagePurchasePrice]);
        }
    }


    /**
     * إرسال تفاصيل فاتورة إلى العميل عبر WhatsApp.
     *
     * @param int $customer_id معرّف العميل
     * @param int $Invoice_id معرّف الفاتورة
     * @param string $type نوع الفاتورة (مثل 'SaleInvoice', 'ReturnSaleInvoice', 'purchaseInvoice', 'returnPurchaseInvoice')
     * @param \Illuminate\Http\Request $request الطلب الذي يحتوي على تفاصيل إضافية
     * @return \Illuminate\Http\JsonResponse استجابة JSON تحتوي على حالة العملية
     */
    public function whatsAppInvoiceToCustomer($customer_id, $Invoice_id, $type, $request)
    {
        // الحصول على رصيد العميل
        $customerBalance = $this->customerBalance($request);
        $customerBalance = $customerBalance['customer_balance'];

        // تحديد نوع الفاتورة ومعالجة البيانات بناءً على النوع
        switch ($type) {
            case 'SaleInvoice':
                $Invoice = SaleInvoice::find($Invoice_id);

                $moneyTransfers = MoneyTransfer::where('sale_invoice_id', $Invoice->id)->get();

                if (!empty($moneyTransfers)) {
                    $moneyTransfersData = [];
                    foreach ($moneyTransfers as $element) {
                        $user = User::find($element['user_id']);
                        $moneyTransfersData[] = [
                            'user_name' => $user->first_name . ' ' . $user->father_name . ' ' . $user->last_name,
                            'mobile' => $user->mobile,
                            'amount' => $element['amount'],
                        ];
                    }
                }

                $text = "فاتورة مبيع رقم : " . $Invoice->code . "\n" .
                    "مجموع صافي قلم الفاتورة: \n" . $Invoice->total_net_price . ' ' . $Invoice->currency->name .  "\n";
                break;
            case 'ReturnSaleInvoice':
                $Invoice = ReturnSaleInvoice::find($Invoice_id);

                $text = "فاتورة مرتجع مبيع رقم : " . $Invoice->code . "\n" .
                    "مجموع صافي قلم الفاتورة: \n" . $Invoice->total_net_price . ' ' . $Invoice->currency->name . "\n";
                break;
            case 'purchaseInvoice':
                $Invoice = PurchaseInvoice::find($Invoice_id);

                $text = "فاتورة شراء رقم : " . $Invoice->code . "\n" .
                    "مجموع صافي قلم الفاتورة: \n" . $Invoice->total_net_price . ' ' . $Invoice->currency->name . "\n";
                break;
            case 'returnPurchaseInvoice':
                $Invoice = ReturnPurchaseInvoice::find($Invoice_id);

                $text = "فاتورة مرتجع شراء رقم : " . $Invoice->code . "\n" .
                    "مجموع صافي قلم الفاتورة: \n" . $Invoice->total_net_price . ' ' . $Invoice->currency->name . "\n";
                break;
        }
        $text .= "=================\n";
        $text .= " الرصيد:\n" . $customerBalance . "\n";
        if (!empty($moneyTransfersData)) {
            $text .= "=================\n";
            foreach ($moneyTransfersData as $transfer) {
                $text .=
                    "اسم المستلم: \n" .
                    $transfer['user_name'] . "\n" .
                    "هاتف المستلم: \n" .
                    $transfer['mobile'] . "\n" .
                    "مبلغ الحوالة : \n" .
                    $transfer['amount'] . "\n";
            }
        }
        try {

            $token = env('ULTRAMSG_TOKEN');
            if (!$token) {
                return 0;
            }
            $Customer = Customer::find($customer_id);
            $params = array(
                'token' => $token,
                'to' => $Customer->whatsapp,
                'body' => $text,
                'priority' => '1',
                'referenceId' => '',
                'msgId' => '',
                'mentions' => ''
            );

            $client = new Client();
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ];
            $options = ['form_params' => $params];
            $request = new RequestWhatsapp('POST', 'https://api.ultramsg.com/instance88819/messages/chat', $headers);
            $res = $client->sendAsync($request, $options)->wait();
            sleep(1);
            $whatsAppController = new WhatsAppController;
            $requestWhatsApp = new Request;
            $requestWhatsApp['phone'] = $Customer->whatsapp;
            $whatsAppFunction = $whatsAppController->getMessagesByPhone($requestWhatsApp);
            $whatsAppControllerOriginal = $whatsAppFunction->getOriginalContent();
            if ($whatsAppControllerOriginal['status'] != 200) {
                return ['error' => $whatsAppControllerOriginal['message']];
            }
            return ['error' => ''];
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return $this->apiResponse(null, $message, 500);
        }
    }


    /**
     * إرسال رسالة ترحيب إلى العميل عبر WhatsApp.
     *
     * @param int $customer_id معرّف العميل
     * @return \Illuminate\Http\JsonResponse استجابة JSON تحتوي على حالة العملية
     */
    public function sendWelcomeMessage($customer_id)
    {
        $customer = Customer::find($customer_id);
        $token = env('ULTRAMSG_TOKEN');

        $message = "أهلاً وسهلاً بك أستاذ  $customer->name. أنا البوت الخاص بمايكروتيك 🤖 ، سأكون معك إذا احتجت لأي مساعدة.
        رقمك الخاص هو $customer->whatsAppVerification.
        --------------------------------------------------------
        كشف حساب إجمالي يرجى إرسال 1.
        كشف حساب التفصيلي يرجى إرسال 2.
        الاسعار  يرجى إرسال 3.
        البنوك يرجى إرسال 4.
        طلب توريد المواد يرجى إرسال 5.
        انشاء توريد المواد يرجى إرسال 6.
        ";

        $params = [
            'token' => $token,
            'to' => $customer->whatsapp,
            'body' => $message,
            'priority' => '1',
            'referenceId' => '',
            'msgId' => '',
            'mentions' => ''
        ];

        $client = new Client();
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
        $options = ['form_params' => $params];
        $request = new RequestWhatsapp('POST', 'https://api.ultramsg.com/instance88819/messages/chat', $headers);
        $res = $client->sendAsync($request, $options)->wait();
    }

    /**
     * معالجة تكاليف المنتجات في فاتورة الشراء.
     *
     * هذه الدالة تقوم بحساب التكاليف الإضافية لكل منتج في فاتورة الشراء
     * بناءً على سندات المصاريف المربوطة بهذه الفاتورة. يتم إضافة هذه
     * التكاليف الإضافية إلى سعر تكلفة المنتج الحالي وتحديث قاعدة البيانات.
     *
     * @param int $purchaseInvoiceId معرف فاتورة الشراء.
     * @return void
     */
    public function handlePurchaseInvoiceProductsCosts($purchaseInvoiceId)
    {
        // العثور على فاتورة الشراء باستخدام معرف الفاتورة
        $purchaseInvoice = PurchaseInvoice::find($purchaseInvoiceId);

        // الحصول على معرف العملة المستخدمة في الفاتورة
        $invoiceCurrencyId = $purchaseInvoice->currency_id;

        // حساب المبلغ الإجمالي لسندات المصاريف المرتبطة بالفاتورة بعد تحويل العملات
        // $bondsAmount = $purchaseInvoice->expensesBonds->map(function ($bond) use ($invoiceCurrencyId) {
        //     return $this->convertBondCurrency($bond, 'ExpensesBond', $invoiceCurrencyId);
        // });
        $bondsAmount = 0;
        $bonds = $purchaseInvoice->expensesBonds;
        foreach ($bonds as $bond) {
            $bondsAmount += $this->convertBondCurrency($bond, 'ExpensesBond', $invoiceCurrencyId);
        }

        // حساب إجمالي كمية المنتجات في الفاتورة
        $invoiceTotalQuantity = $purchaseInvoice->products()->sum('quantity');

        // حساب التكلفة الإضافية لكل وحدة من المنتجات
        $extraCost = $bondsAmount / $invoiceTotalQuantity;

        // تكرار جميع المنتجات في الفاتورة لتحديث سعر التكلفة لكل منتج
        foreach ($purchaseInvoice->products as $product) {
            // حساب سعر المنتج الحالي لكل وحدة
            $productPrice = $product->pivot->net_price / $product->pivot->quantity;

            // إضافة التكلفة الإضافية إلى سعر التكلفة الحالي
            $newCostPrice = $productPrice + $extraCost;

            // تحويل سعر التكلفة الجديد إلى عملة الفاتورة
            $convertedNewCostPrice = $this->convertProductPrice($newCostPrice, $product->id, $purchaseInvoice->id, 'PurchaseInvoice');

            // تحديث سعر التكلفة في قاعدة البيانات
            $product->price->update(['cost_price' => $convertedNewCostPrice]);
        }
    }

    /**
     * معالجة بيانات الفواتير الجمركية عند إضافة أو تحديث فاتورة الشراء
     *
     * @param int $purchaseInvoiceId معرف فاتورة الشراء
     * @param \Illuminate\Http\Request $request بيانات الطلب
     * @return void
     */
    public function handlePurchaseInvoiceCustomsData($purchaseInvoiceId, $request)
    {
        if ($request->has('customs_data')) {
            foreach ($request->customs_data as $customsData) {
                $type = $customsData['type'] ?? 'add';  // نوع العملية (إضافة، تعديل، حذف)
                $customsDataId = $customsData['id'] ?? null;  // معرف البيانات الجمركية

                switch ($type) {
                    case 'add':
                        // إضافة بيانات جمركية جديدة
                        $this->addCustomsData($purchaseInvoiceId, $customsData, $request->date);
                        break;

                    case 'edit':
                        if ($customsDataId) {
                            // تعديل بيانات جمركية موجودة
                            $this->editCustomsData($customsDataId, $customsData);
                        }
                        break;

                    case 'delete':
                        if ($customsDataId) {
                            // حذف بيانات جمركية موجودة
                            $this->deleteCustomsData($customsDataId);
                        }
                        break;
                }
            }
        }
    }


    /**
     * إضافة بيانات جمركية جديدة
     *
     * @param int $purchaseInvoiceId معرف فاتورة الشراء
     * @param array $customsData بيانات الجمركية
     * @param string $date تاريخ الفاتورة
     * @return void
     */
    private function addCustomsData($purchaseInvoiceId, $customsData, $date)
    {
        // إعداد البيانات الجمركية
        $customsData['purchase_invoice_id'] = $purchaseInvoiceId;
        $customsData['date'] = $date;
        $customsData['created_by'] = auth()->user()->id;

        // إنشاء السجل الجمركي الجديد
        $customsDataModel = CustomsData::create($customsData);

        // معالجة الملفات الجمركية
        $this->handleCustomsDataFiles($customsDataModel, $customsData['files'] ?? []);
    }

    /**
     * تعديل بيانات جمركية موجودة
     *
     * @param int $customsDataId معرف البيانات الجمركية
     * @param array $customsData بيانات الجمركية الجديدة
     * @return void
     */
    private function editCustomsData($customsDataId, $customsData)
    {
        // جلب البيانات الجمركية الحالية
        $existingCustomsData = CustomsData::find($customsDataId);

        // معالجة سجل التحديثات
        $updateHistory = $existingCustomsData->update_history ?? [];
        $updateHistory[] = [
            'user_id' => auth()->user()->id,
            'timestamp' => now()->toDateTimeString()
        ];
        $existingCustomsData->update_history = $updateHistory;

        if (!isset($customsData['product_id']) && $existingCustomsData->product_id)
            $existingCustomsData->product()->dissociate();

        if (!isset($customsData['category_id']) && $existingCustomsData->category_id)
            $existingCustomsData->category()->dissociate();

        // تحديث البيانات الجمركية
        $existingCustomsData->update($customsData);

        // معالجة الملفات الجمركية
        $this->handleCustomsDataFiles($existingCustomsData, $customsData['files'] ?? []);
    }

    /**
     * حذف بيانات جمركية موجودة
     *
     * @param int $customsDataId معرف البيانات الجمركية
     * @return void
     */
    private function deleteCustomsData($customsDataId)
    {
        // جلب البيانات الجمركية الحالية
        $customsData = CustomsData::find($customsDataId);

        // حذف الملفات المرتبطة بالبيانات الجمركية
        foreach ($customsData->files as $file) {
            Storage::delete($file->file);
            $file->delete();
        }

        // حذف السجل الجمركي
        $customsData->delete();
    }

    /**
     * معالجة الملفات المرتبطة بالبيانات الجمركية
     *
     * @param \App\Models\CustomsData $customsDataModel نموذج البيانات الجمركية
     * @param array $files قائمة الملفات
     * @return void
     */
    private function handleCustomsDataFiles($customsDataModel, $files)
    {
        foreach ($files as $fileData) {
            if ($fileData instanceof \Illuminate\Http\UploadedFile) {
                $fileData = [
                    'file' => $fileData,
                    'type' => 'add', // Default type
                ];
            } else {
                // Ensure that 'type' is set to 'add' if it's not provided
                if (!isset($fileData['type'])) {
                    $fileData['type'] = 'add';
                }
            }
            $fileType = $fileData['type'] ?? 'add';  // نوع العملية (إضافة، تعديل، حذف)
            $fileId = $fileData['id'] ?? null;  // معرف الملف الجمركي

            switch ($fileType) {
                case 'add':
                    // إضافة ملف جمركي جديد
                    $this->addCustomsDataFile($customsDataModel, $fileData['file']);
                    break;

                case 'edit':
                    if ($fileId) {
                        // تعديل ملف جمركي موجود
                        $this->editCustomsDataFile($fileId, $fileData['file']);
                    }
                    break;

                case 'delete':
                    if ($fileId) {
                        // حذف ملف جمركي موجود
                        $this->deleteCustomsDataFile($fileId);
                    }
                    break;
            }
        }
    }

    /**
     * إضافة ملف جمركي جديد
     *
     * @param \App\Models\CustomsData $customsDataModel نموذج البيانات الجمركية
     * @param \Illuminate\Http\UploadedFile $file الملف الجديد
     * @return void
     */
    private function addCustomsDataFile($customsDataModel, $file)
    {
        // تخزين الملف والحصول على مساره ونوعه
        $path = $this->storeFile($file, $customsDataModel->id);

        // إنشاء سجل للملف الجمركي
        CustomsDataFile::create([
            'file' => $path['path'],
            'type' => $path['type'],
            'customs_data_id' => $customsDataModel->id,
        ]);
    }

    /**
     * تعديل ملف جمركي موجود
     *
     * @param int $fileId معرف الملف الجمركي
     * @param \Illuminate\Http\UploadedFile $newFile الملف الجديد
     * @return void
     */
    private function editCustomsDataFile($fileId, $newFile)
    {
        // جلب الملف الجمركي الحالي
        $existingFile = CustomsDataFile::find($fileId);

        // حذف الملف القديم من التخزين
        $this->deleteFile($existingFile->file, $existingFile->type);

        // تخزين الملف الجديد والحصول على مساره ونوعه
        $path = $this->storeFile($newFile, $existingFile->customs_data_id);

        // تحديث سجل الملف الجمركي
        $existingFile->update([
            'file' => $path['path'],
            'type' => $path['type'],
        ]);
    }


    /**
     * حذف ملف جمركي موجود
     *
     * @param int $fileId معرف الملف الجمركي
     * @return void
     */
    private function deleteCustomsDataFile($fileId)
    {
        // جلب الملف الجمركي الحالي
        $file = CustomsDataFile::find($fileId);

        // حذف الملف من التخزين
        Storage::delete($file->file);

        // حذف سجل الملف الجمركي
        $file->delete();
    }

    /**
     * تخزين الملف على الخادم وإرجاع مساره ونوعه
     *
     * @param \Illuminate\Http\UploadedFile $file الملف المراد تخزينه
     * @param int $customsDataId معرف البيانات الجمركية المرتبط بها الملف
     * @return array معلومات المسار والنوع
     */
    private function storeFile($file, $customsDataId)
    {
        $originalName = $file->getClientOriginalName();  // الاسم الأصلي للملف
        $extension = $file->getClientOriginalExtension();  // امتداد الملف
        $newName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . $customsDataId . '_' . date('Y.m.d_His') . '.' . $extension;

        $path = null;
        $type = null;

        // تخزين الملف حسب نوعه
        if (in_array($extension, ['jpeg', 'png', 'gif', 'svg', 'jpg'])) {
            $type = FileTypeConstants::FILE_TYPE_IMAGE;
            $path = $file->storeAs('Customs-Data-Files', $newName, 'images');
        } elseif (in_array($extension, ['mp4', 'avi', 'mov'])) {
            $type = FileTypeConstants::FILE_TYPE_VIDEO;
            $path = $file->storeAs('Customs-Data-Files', $newName, 'videos');
        } elseif (in_array($extension, ['pdf'])) {
            $type = FileTypeConstants::FILE_TYPE_FILE;
            $path = $file->storeAs('Customs-Data-Files', $newName, 'files');
        }

        // إرجاع المسار والنوع
        return ['path' => $path, 'type' => $type];
    }



    /**
     * الحصول على سلسلة التحويلات المرتبطة بنموذج معين
     *
     * هذه الدالة تعيد سلسلة التحويلات التي مر بها النموذج، بدءًا من النموذج الحالي
     * والعودة إلى جميع النماذج السابقة التي تم تحويل النموذج منها. على سبيل المثال،
     * إذا تم تحويل فاتورة مبيع من طلب، والطلب من فاتورة شراء، سيتم إرجاع السلسلة
     * بترتيب عكسي مع تفاصيل كل تحويل.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model  النموذج الأساسي الذي نريد الحصول على سلسلة التحويلات له
     * @return array  مصفوفة تحتوي على تفاصيل كل تحويل، تتضمن نوع النموذج ومعرفه والمستخدم الذي أنشأه
     */
    public function getConversionChain($model)
    {
        // مصفوفة لتخزين سلسلة التحويلات
        $conversionChain = [];

        // التحقق من وجود تحويل للنموذج الحالي
        if ($model->convertedFrom) {
            // إذا كان النموذج قد تم تحويله، يتم البدء بالنموذج الذي تم التحويل منه
            $model = $model->convertedFrom->convertible;
        } else {
            // إذا لم يكن هناك تحويل، يتم تعيين النموذج إلى null
            $model = null;
        }

        // التكرار عبر سلسلة التحويلات
        while ($model) {
            if (class_basename($model) === 'InOutStorehouse') {
                if ($model->type == 1) {
                    $modelName = 'InStorehouse';
                } else {
                    $modelName = 'OutStorehouse';
                }
            } else {
                $modelName = class_basename($model);
            }
            // إنشاء عنصر يحتوي على تفاصيل التحويل
            $item = [
                'model' => $modelName, // اسم النموذج (بدون المسار الكامل)
                'id' => $model->id, // معرف النموذج
                'created_by' => [
                    'id' => $model->createdBy->id, // معرف المستخدم الذي أنشأ النموذج
                    'first_name' => $model->createdBy->first_name, // الاسم الأول للمستخدم
                    'last_name' => $model->createdBy->last_name, // الاسم الأخير للمستخدم
                ],
                'created_at' => $model->created_at, // تاريخ إنشاء النموذج
                'checking_log' => $model->checkingLog ? [ // سجل التدقيق
                    'checked_by' => $model->checkingLog[0]['user'] ?? null,
                    'checked_at' => $model->checkingLog[0]['created_at'] ?? null
                ] : null,
                'preparing_log' => $model->preparingLog ? [ // سجل التجهيز
                    'prepared_by' => $model->preparingLog['user'],
                    'prepared_at' => $model->preparingLog['created_at'],
                ] : null,
                'pricing_log' => $model->pricingLog ? [ // سجل التسعير
                    'priced_by' => $model->pricingLog['user'],
                    'priced_at' => $model->pricingLog['created_at'],
                ] : null,
            ];

            // إضافة العنصر إلى سلسلة التحويلات
            $conversionChain[] = $item;

            // التحقق مما إذا كان النموذج قد تم تحويله من نموذج آخر
            if ($model->convertedFrom) {
                // الانتقال إلى النموذج الذي تم التحويل منه
                $model = $model->convertedFrom->convertible;
            } else {
                // إذا لم يكن هناك تحويل آخر، إنهاء الحلقة
                $model = null;
            }
        }

        // عكس المصفوفة للحصول على التحويلات بالترتيب الصحيح
        return array_reverse($conversionChain);
    }
}
