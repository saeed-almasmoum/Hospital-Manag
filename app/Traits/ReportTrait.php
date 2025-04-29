<?php

namespace App\Traits;

use App\Models\BankMoneyTransferBond;
use App\Models\BondsExchange;
use App\Models\Box;
use App\Models\BoxBalanceBond;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DifferenceBond;
use App\Models\Exchange;
use App\Models\ExpensesBond;
use App\Models\FirstTermInvoice;
use App\Models\GainDiscountBond;
use App\Models\GivenDiscountBond;
use App\Models\InvoicesExchange;
use App\Models\MoneyTransferBond;
use App\Models\OpeningBalanceBond;
use App\Models\PartnerPayBond;
use App\Models\PartnersExpensesBond;
use App\Models\PayBond;
use App\Models\PersonalExpensesBond;
use App\Models\Price;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\ReceiptBond;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnSaleInvoice;
use App\Models\SaleInvoice;
use App\Models\StorehouseEquality;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserMoneyTransferBond;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

trait ReportTrait
{
    /**
     * تحويل سعر الفاتورة الصافي إلى عملة مختلفة إذا لزم الأمر.
     *
     * @param $invoice كائن الفاتورة الذي يحتوي على سعر صافي ومعلومات العملة.
     * @param $invoicesName اسم جدول الفاتورة.
     * @param $reportsCurrency رمز العملة الذي يجب تحويل سعر الصافي إليه.
     * @return float سعر الصافي المحول إلى العملة المحددة.
     */
    public function convertInvoiceCurrency($invoice, $invoicesName, $reportsCurrency)
    {
        // الحصول على إجمالي سعر الفاتورة الصافي
        $totalNetPrice = $invoice->total_net_price;


        // الحصول على معرف العملة الافتراضية
        $default_currency = Currency::where('is_default', 1)->value('id');

        // التحقق مما إذا كانت عملة التقارير تختلف عن عملة الفاتورة
        if ($reportsCurrency != $invoice->currency_id) {

            // إذا كانت عملة التقارير هي العملة الافتراضية
            if ($reportsCurrency == $default_currency) {

                // الحصول على سعر الصرف لعملة الفاتورة وتحويل سعر الصافي
                $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                    ->where('currency_id', $invoice->currency_id)
                    ->where('invoicesable_type', $invoicesName)->value('exchange');

                $totalNetPrice *= $ex;
            }
            // إذا كانت عملة الفاتورة هي العملة الافتراضية
            elseif ($invoice->currency_id == $default_currency) {
                // الحصول على سعر الصرف لعملة التقارير وتحويل سعر الصافي
                $ex = InvoicesExchange::where('invoicesable_id', $invoice->id)
                    ->where('currency_id', $reportsCurrency)
                    ->where('invoicesable_type', $invoicesName)->value('exchange');

                $totalNetPrice /= $ex;
            }
            // إذا كانت كل من عملة الفاتورة وعملة التقارير مختلفة عن الافتراضية
            else {

                // الحصول على أسعار الصرف لكل من عملة الفاتورة وعملة التقارير
                $invoicesEx = InvoicesExchange::where('invoicesable_id', $invoice->id)
                    ->where('currency_id', $invoice->currency_id)
                    ->where('invoicesable_type', $invoicesName)->value('exchange');

                $reportEx = Exchange::where('currency_id', $reportsCurrency)->latest()->value('value');

                // تحويل سعر الصافي باستخدام كل من أسعار الصرف
                $totalNetPrice = ($totalNetPrice * $invoicesEx) / $reportEx;
            }
        }
        // إرجاع إجمالي سعر الفاتورة الصافي المحول
        return $totalNetPrice;
    }

    /**
     * تحويل قيمة سند إلى العملة المطلوبة.
     *
     * @param object $bond كائن السند الذي يحتوي على معلومات مثل العملة والمبلغ
     * @param string $bondsName اسم نوع السند (مثل 'OpeningBalanceBond', 'DifferenceBond', 'FirstTermBond')
     * @param int $reportsCurrency معرّف عملة التقارير التي يتم تحويل القيمة إليها
     * @return float القيمة المحوّلة للعملة المطلوبة
     */
    public function convertBondCurrency($bond, $bondsName, $reportsCurrency)
    {
        if ($bondsName === 'OpeningBalanceBond') {
            $amount = $bond->debtor != null ? $bond->debtor : $bond->lender;
        } elseif($bondsName === 'DifferenceBond') {
            $amount = $bond->balance_difference;
        } elseif ($bondsName === 'FirstTermBond') {
            $amount = $bond->capital;
        }else{
            $amount = $bond->amount;
        }

        // الحصول على معرف العملة الافتراضية
        $default_currency = Currency::where('is_default', 1)->value('id');

        // التحقق مما إذا كانت عملة التقارير تختلف عن عملة الفاتورة
        if ($reportsCurrency != $bond->currency_id) {

            // إذا كانت عملة التقارير هي العملة الافتراضية
            if ($reportsCurrency == $default_currency) {
                // الحصول على سعر الصرف لعملة الفاتورة وتحويل سعر الصافي
                $ex = BondsExchange::where('bondsable_id', $bond->id)
                    ->where('currency_id', $bond->currency_id)
                    ->where('bondsable_type', $bondsName)->value('exchange');

                $amount *= $ex;
            }
            // إذا كانت عملة الفاتورة هي العملة الافتراضية
            elseif ($bond->currency_id == $default_currency) {
                // الحصول على سعر الصرف لعملة التقارير وتحويل سعر الصافي
                $ex = BondsExchange::where('bondsable_id', $bond->id)
                    ->where('currency_id', $reportsCurrency)
                    ->where('bondsable_type', $bondsName)->value('exchange');
                $amount /= $ex;
            }
            // إذا كانت كل من عملة الفاتورة وعملة التقارير مختلفة عن الافتراضية
            else {
                // الحصول على أسعار الصرف لكل من عملة الفاتورة وعملة التقارير
                $bondEx = BondsExchange::where('bondsable_id', $bond->id)
                    ->where('currency_id', $bond->currency_id)
                    ->where('bondsable_type', $bondsName)->value('exchange');
                $reportEx = Exchange::where('currency_id', $reportsCurrency)->latest()->value('value');
                // تحويل سعر الصافي باستخدام كل من أسعار الصرف
                $amount = ($amount * $bondEx) / $reportEx;
            }
        }
        // إرجاع إجمالي سعر الفاتورة الصافي المحول
        return $amount;
    }

    /**
     * تحويل سعر المنتج إلى العملة المطلوبة بناءً على نوع السعر المحدد.
     *
     * @param array $modifiedProduct بيانات المنتج، بما في ذلك الأسعار والعملات
     * @param int $priceTypeId نوع السعر المطلوب تحويله، حيث يُمثل كل رقم نوع سعر مختلف (مثل 1 = سعر الجملة، 2 = السعر المنفصل، إلخ)
     * @param int $reportsCurrency معرّف العملة التي سيتم تحويل السعر إليها
     * @return float السعر المحول للعملة المطلوبة
     */
    public function convertLastTermCurrency($modifiedProduct, $priceTypeId, $reportsCurrency)
    {

        // if ($priceTypeId == 1) {
        //     $productPrice = $modifiedProduct['price']['wholesale_price'];
        // } elseif ($priceTypeId == 2) {
        //     $productPrice = $modifiedProduct['price']['separate_price'];
        // } elseif ($priceTypeId == 3) {
        //     $productPrice = $modifiedProduct['price']['consumer_price'];
        // } elseif ($priceTypeId == 4) {
        //     $productPrice = $modifiedProduct['price']['state_price'];
        // } elseif ($priceTypeId == 5) {
        //     $productPrice = $modifiedProduct['price']['cost_price'];
        // } elseif ($priceTypeId == 6) {
        //     $productPrice = $modifiedProduct['price']['purchasing_price'];
        // } elseif ($priceTypeId == 7) {
        //     $productPrice = $modifiedProduct['price']['list_price'];
        // } elseif ($priceTypeId == 8) {
        //     $productPrice = $modifiedProduct['price']['average_purchase_price'];
        // }
        
        $productPrice = Price::where('product_id', $modifiedProduct['id'])->where('price_type_id', $priceTypeId)->value('value');
        $default_currency = Currency::where('is_default', 1)->value('id');

        $productCurrencyID = $modifiedProduct['currency_id'];

        // // التحقق مما إذا كانت عملة التقارير تختلف عن عملة الفاتورة
        if ($reportsCurrency != $productCurrencyID) {

            // إذا كانت عملة التقارير هي العملة الافتراضية
            if ($reportsCurrency == $default_currency) {
                // الحصول على سعر الصرف لعملة الفاتورة وتحويل سعر الصافي
                $ex = Exchange::where('currency_id', $productCurrencyID)->latest()->value('value');

                $productPrice *= $ex;
            }
            // إذا كانت عملة الفاتورة هي العملة الافتراضية
            elseif ($productCurrencyID == $default_currency) {
                // الحصول على سعر الصرف لعملة التقارير وتحويل سعر الصافي
                $ex = Exchange::where('currency_id', $reportsCurrency)->latest()->value('value');

                $productPrice /= $ex;
            }
            // إذا كانت كل من عملة الفاتورة وعملة التقارير مختلفة عن الافتراضية
            else {
                // الحصول على أسعار الصرف لكل من عملة الفاتورة وعملة التقارير
                $invoicesEx = Exchange::where('currency_id', $productCurrencyID)->latest()->value('value');

                $reportEx = Exchange::where('currency_id', $reportsCurrency)->latest()->value('value');
                // تحويل سعر الصافي باستخدام كل من أسعار الصرف
                $productPrice = ($productPrice * $invoicesEx) / $reportEx;
            }
        }
        $productPrice *= $modifiedProduct['rest'];


        return $productPrice;
    }


    /**
     * حساب رصيد العميل
     * 
     * هذه الدالة تقوم بحساب رصيد العميل من خلال جمع البيانات المالية من الفواتير والسندات المختلفة
     * مثل فواتير المبيعات والمشتريات وسندات القبض والدفع وغيرها.
     *
     * @param \Illuminate\Http\Request $request كائن الطلب الذي يحتوي على مدخلات الحساب
     * @return array يحتوي على رصيد العميل والبيانات المالية المفصلة
     */
    public function customerBalance(Request $request)
    {
        $reportCurrencyId = $request->input('report_currency_id');
        $dataCurrencyId = $request->input('data_currency_id');
        $categoryId = $request->input('category_id');
        $fromDate = $request->input('from_date');
        $toDate = !empty($request->input('to_date')) ? $request->input('to_date') : date('Y-m-d', strtotime(now()));
        $customerId = $request->input('customer_id');

        $query1 = SaleInvoice::with(['currency'])->where('customer_id', $customerId);
        $query2 = ReturnSaleInvoice::with(['currency'])->where('customer_id', $customerId);
        $query3 = ReceiptBond::with(['currency'])->where('customer_id', $customerId);
        $query4 = PayBond::with(['currency'])->where('customer_id', $customerId);
        $query5 = OpeningBalanceBond::with(['currency'])->where('customer_id', $customerId);
        $query6 = GivenDiscountBond::with(['currency'])->where('customer_id', $customerId);
        $query7 = DifferenceBond::with(['currency'])->where('customer_id', $customerId);
        $query8 = PurchaseInvoice::with(['currency'])->where('customer_id', $customerId);
        $query9 = ReturnPurchaseInvoice::with(['currency'])->where('customer_id', $customerId);


        if (!empty($fromDate) && !empty($toDate)) {
            $query1->whereBetween('date', [$fromDate, $toDate]);
            $query2->whereBetween('date', [$fromDate, $toDate]);
            $query3->whereBetween('date', [$fromDate, $toDate]);
            $query4->whereBetween('date', [$fromDate, $toDate]);
            $query5->whereBetween(DB::raw('DATE(created_at)'), [$fromDate, $toDate]);
            $query6->whereBetween('date', [$fromDate, $toDate]);
            $query7->whereBetween('date', [$fromDate, $toDate]);
            $query8->whereBetween('date', [$fromDate, $toDate]);
            $query9->whereBetween('date', [$fromDate, $toDate]);
        }

        if (!empty($dataCurrencyId)) {

            $query1->where('currency_id', $dataCurrencyId);
            $query2->where('currency_id', $dataCurrencyId);
            $query3->where('currency_id', $dataCurrencyId);
            $query4->where('currency_id', $dataCurrencyId);
            $query5->where('currency_id', $dataCurrencyId);
            $query6->where('currency_id', $dataCurrencyId);
            $query7->where('currency_id', $dataCurrencyId);
            $query8->where('currency_id', $dataCurrencyId);
            $query9->where('currency_id', $dataCurrencyId);
        }

        if (!empty($categoryId)) {

            $category = Category::find($categoryId);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $categoryId;
            $query1->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query2->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query8->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query9->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        }

        $saleInvoices = $query1->with('products')->get(['id', 'code', 'date', 'total_net_price', 'currency_id', 'status', 'created_at']);
        $returnSaleInvoices = $query2->with('products')->get(['id', 'code', 'date', 'total_net_price', 'currency_id', 'status', 'created_at']);
        $purchaseInvoices = $query8->with('products')->get(['id', 'code', 'date', 'total_net_price', 'currency_id', 'status', 'created_at']);
        $returnPurchaseInvoices = $query9->with('products')->get(['id', 'code', 'date', 'total_net_price', 'currency_id', 'status', 'created_at']);
        $receiptBonds = $query3->get(['id', 'code', 'amount', 'date', 'currency_id','created_at']);
        $payBonds = $query4->get(['id', 'code', 'amount', 'date', 'currency_id','created_at']);
        $openingBalanceBonds = $query5->get(['id', 'code', 'debtor', 'lender', 'created_at', 'currency_id']);
        $givenDiscountBonds = $query6->get(['id', 'code', 'amount', 'date', 'currency_id','created_at']);
        $differenceBonds = $query7->get(['id', 'code', 'balance_difference', 'date', 'currency_id','created_at']);


        $in = 0;
        $out = 0;

        foreach ($saleInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'فاتورة مبيع';
            $Invoice['lender'] = 0;
            $Invoice['debtor'] = $this->convertInvoiceCurrency($Invoice, 'SaleInvoice', $reportCurrencyId);
            if ($Invoice->status == 1) $Invoice['statement'] = 'نقدي';
            elseif ($Invoice->status == 2) $Invoice['statement'] = 'آجل';
            elseif ($Invoice->status == 3) $Invoice['statement'] = 'حوالة';
            elseif ($Invoice->status == 4) $Invoice['statement'] = 'بنك';
            $Invoice['exchanges'] =  $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Invoice['total_net_price']);
            $in += $Invoice['debtor'];
        }
        foreach ($returnSaleInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'فاتورة مرتجع مبيع';
            $Invoice['debtor'] = 0;
            $Invoice['lender'] = $this->convertInvoiceCurrency($Invoice, 'ReturnSaleInvoice', $reportCurrencyId);
            $Invoice['statement'] = ($Invoice->status == 1) ? 'نقدي' : 'آجل';
            $Invoice['exchanges'] =  $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Invoice['total_net_price']);
            $out += $Invoice['lender'];
        }

        foreach ($purchaseInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'فاتورة شراء';
            $Invoice['debtor'] = 0;
            $Invoice['lender'] = $this->convertInvoiceCurrency($Invoice, 'PurchaseInvoice', $reportCurrencyId);
            if ($Invoice->status == 1) $Invoice['statement'] = 'نقدي';
            elseif ($Invoice->status == 2) $Invoice['statement'] = 'آجل';
            elseif ($Invoice->status == 4) $Invoice['statement'] = 'بنك';
            $Invoice['exchanges'] =  $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Invoice['total_net_price']);
            $out += $Invoice['lender'];
        }

        foreach ($returnPurchaseInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'فاتورة مرتجع شراء';
            $Invoice['lender'] = 0;
            $Invoice['debtor'] = $this->convertInvoiceCurrency($Invoice, 'ReturnPurchaseInvoice', $reportCurrencyId);
            $Invoice['statement'] = ($Invoice->status == 1) ? 'نقدي' : 'آجل';
            $Invoice['exchanges'] =  $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Invoice['total_net_price']);
            $in += $Invoice['debtor'];
        }
        foreach ($receiptBonds as $Bond) {
            $Bond['name'] = 'سند قبض';
            $Bond['debtor'] = 0;
            $Bond['lender'] = $this->convertBondCurrency($Bond, 'ReceiptBond', $reportCurrencyId);
            $Bond['statement'] = 'سند قبض';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['amount']);
            $out += $Bond['lender'];
        }

        foreach ($payBonds as $Bond) {
            $Bond['name'] = 'سند دفع';
            $Bond['lender'] = 0;
            $Bond['debtor'] = $this->convertBondCurrency($Bond, 'PayBond', $reportCurrencyId);
            $Bond['statement'] = 'سند دفع';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['amount']);
            $in += $Bond['debtor'];
        }
        foreach ($openingBalanceBonds as $Bond) {
            $Bond['name'] = 'سند قيد افتتاحي';
            $Bond['date'] = date('Y-m-d', strtotime($Bond['created_at']));
            $Bond['statement'] = ($Bond->debtor) ? 'مدين' : 'دائن';
            if($Bond['debtor']) {
                $Bond['lender'] = 0;
                $Bond['debtor'] = $this->convertBondCurrency($Bond, 'OpeningBalanceBond', $reportCurrencyId);
                $in += $Bond['debtor'];
            } else {
                $Bond['debtor'] = 0;
                $Bond['lender'] = $this->convertBondCurrency($Bond, 'OpeningBalanceBond', $reportCurrencyId);
                $out += $Bond['lender'];
            }
            
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
        }
        foreach ($givenDiscountBonds as $Bond) {
            $Bond['name'] = 'سند حسم ممنوح';
            $Bond['debtor'] = 0;
            $Bond['lender'] = $this->convertBondCurrency($Bond, 'GivenDiscountBond', $reportCurrencyId);
            $Bond['statement'] = 'سند حسم ممنوح';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['amount']);
            $out += $Bond['lender'];
        }

        foreach ($differenceBonds as $Bond) {
            $Bond['name'] = 'سند فرق حساب';
            $bondAmount = $this->convertBondCurrency($Bond, 'DifferenceBond', $reportCurrencyId); 
            if ($bondAmount < 0){
            $bondAmount *= -1;
            $Bond['lender'] = $bondAmount;
            $Bond['debtor'] = 0;
            $out += $Bond['lender'];
            }else {
                $Bond['lender'] = 0;
                $Bond['debtor'] = $bondAmount;
                $in += $Bond['debtor'];
            }
            $Bond['statement'] = 'سند فرق حساب';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['balance_difference']);
       
        }

        $data = array_merge(
            $saleInvoices->toArray(),
            $returnSaleInvoices->toArray(),
            $purchaseInvoices->toArray(),
            $returnPurchaseInvoices->toArray(),
            $receiptBonds->toArray(),
            $payBonds->toArray(),
            $openingBalanceBonds->toArray(),
            $givenDiscountBonds->toArray(),
            $differenceBonds->toArray()
        );


        // if (!empty($categoryId)) {

        //     $data = array_merge(
        //         $saleInvoices->toArray(),
        //         $returnSaleInvoices->toArray(),
        //         $purchaseInvoices->toArray(),
        //         $returnPurchaseInvoices->toArray(),
        //     );
        // }

        $lastIndex = null;
        foreach ($data as $index => $item ) {
            if ($item['statement'] == 'نقدي'|| $item['statement'] == 'حوالة' || $item['statement'] == 'بنك') {
                $duplicate = $item;
                $duplicate['debtor'] = $item['lender'];
                $duplicate['lender'] = $item['debtor'];
                
                array_splice($data, $lastIndex + 1, 0,[$duplicate]);

                unset($data[$index]['products']);
                
                $lastIndex++;
                
                if ($item['name'] == 'فاتورة مبيع' || $item['name'] == 'فاتورة مرتجع شراء') {
                    $out += $item['debtor'];
                } elseif ($item['name'] == 'فاتورة شراء' || $item['name'] == 'فاتورة مرتجع مبيع') {
                    $in += $item['lender'];
                }
            } else {
                $lastIndex = $index;
            }
        }


        $balance = $in - $out;
        $data = [
            'customer_balance' => $balance,
            'data' => $data,


        ];
        return $data;
    }

    /**
     * حساب رصيد المورد
     *
     * هذه الدالة تحسب رصيد المورد بناءً على فواتير الشراء، فواتير مرتجع الشراء، 
     * سندات القبض، سندات الدفع، سندات القيد الافتتاحي، وسندات الحسم المكتسب.
     *
     * @param Request $request الطلب الوارد
     * @return array مصفوفة تحتوي على رصيد المورد والبيانات المتعلقة
     */
    public function supplierBalance(Request $request)
    {
        // الحصول على بيانات الطلب
        $supplierId = $request->input('supplier_id');
        $reportCurrencyId = $request->input('report_currency_id');
        $dataCurrencyId = $request->input('data_currency_id');
        $categoryId = $request->input('category_id');
        $fromDate = $request->input('from_date');
        $toDate = !empty($request->input('to_date')) ? $request->input('to_date') : date('Y-m-d', strtotime(now()));

        $query3 = ReceiptBond::with(['currency'])->where('supplier_id', $supplierId);
        $query4 = PayBond::with(['currency'])->where('supplier_id', $supplierId);
        $query5 = OpeningBalanceBond::with(['currency'])->where('supplier_id', $supplierId);
        $query6 = GainDiscountBond::with(['currency'])->where('supplier_id', $supplierId);
        $query8 = PurchaseInvoice::with(['currency'])->where('supplier_id', $supplierId);
        $query9 = ReturnPurchaseInvoice::with(['currency'])->where('supplier_id', $supplierId);


        if (!empty($fromDate) && !empty($toDate)) {
            $query3->whereBetween('date', [$fromDate, $toDate]);
            $query4->whereBetween('date', [$fromDate, $toDate]);
            $query5->whereBetween(DB::raw('DATE(created_at)'), [$fromDate, $toDate]);
            $query6->whereBetween('date', [$fromDate, $toDate]);
            $query8->whereBetween('date', [$fromDate, $toDate]);
            $query9->whereBetween('date', [$fromDate, $toDate]);
        }

        if (!empty($dataCurrencyId)) {

            $query3->where('currency_id', $dataCurrencyId);
            $query4->where('currency_id', $dataCurrencyId);
            $query5->where('currency_id', $dataCurrencyId);
            $query6->where('currency_id', $dataCurrencyId);
            $query8->where('currency_id', $dataCurrencyId);
            $query9->where('currency_id', $dataCurrencyId);
        }

        if (!empty($categoryId)) {

            $category = Category::find($categoryId);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $categoryId;
            // تطبيق فلتر على الفواتير بناءً على فئات المنتجات

            $query8->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query9->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });
        }


        $purchaseInvoices = $query8->get(['id', 'code', 'date', 'total_net_price', 'currency_id', 'status','created_at']);
        $returnPurchaseInvoices = $query9->get(['id', 'code', 'date', 'total_net_price', 'currency_id', 'status','created_at']);
        $receiptBonds = $query3->get(['id', 'code', 'amount', 'date', 'currency_id','created_at']);
        $payBonds = $query4->get(['id', 'code', 'amount', 'date', 'currency_id','created_at']);
        $openingBalanceBonds = $query5->get(['id', 'code', 'debtor', 'lender', 'created_at', 'currency_id','created_at']);
        $gainDiscountBonds = $query6->get(['id', 'code', 'amount', 'date', 'currency_id','created_at']);

        $in = 0;
        $out = 0;

        foreach ($purchaseInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'فاتورة شراء';
            $Invoice['debtor'] = 0;
            $Invoice['lender'] = $this->convertInvoiceCurrency($Invoice, 'PurchaseInvoice', $reportCurrencyId);

            if ($Invoice->status == 1) $Invoice['statement'] = 'نقدي';
            elseif ($Invoice->status == 2) $Invoice['statement'] = 'آجل';
            elseif ($Invoice->status == 4) $Invoice['statement'] = 'بنك';
            $Invoice['exchanges'] =  $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Invoice['total_net_price']);
            $out += $Invoice['lender'];
        }

        foreach ($returnPurchaseInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'فاتورة مرتجع شراء';
            $Invoice['lender'] = 0;
            $Invoice['debtor'] = $this->convertInvoiceCurrency($Invoice, 'ReturnPurchaseInvoice', $reportCurrencyId);
            $Invoice['statement'] = ($Invoice->status == 1) ? 'نقدي' : 'آجل';
            $Invoice['exchanges'] =  $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Invoice['total_net_price']);
            $in += $Invoice['debtor'];
        }

        foreach ($receiptBonds as $Bond) {
            $Bond['name'] = 'سند قبض';
            $Bond['debtor'] = 0;
            $Bond['lender'] = $this->convertBondCurrency($Bond, 'ReceiptBond', $reportCurrencyId);
            $Bond['statement'] = 'سند قبض';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['amount']);
            $out += $Bond['lender'];
        }

        foreach ($payBonds as $Bond) {
            $Bond['name'] = 'سند دفع';
            $Bond['lender'] = 0;
            $Bond['debtor'] = $this->convertBondCurrency($Bond, 'PayBond', $reportCurrencyId);
            $Bond['statement'] = 'سند دفع';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['amount']);
            $in += $Bond['debtor'];
        }
        foreach ($openingBalanceBonds as $Bond) {
            $Bond['name'] = 'سند قيد افتتاحي';
            $Bond['date'] = date('Y-m-d', strtotime($Bond['created_at']));
            $Bond['statement'] = ($Bond->debtor) ? 'مدين' : 'دائن';
            if ($Bond['debtor']) {
                $Bond['lender'] = 0;
                $Bond['debtor'] = $this->convertBondCurrency($Bond, 'OpeningBalanceBond', $reportCurrencyId);
                $in += $Bond['debtor'];
            } else {
                $Bond['debtor'] = 0;
                $Bond['lender'] = $this->convertBondCurrency($Bond, 'OpeningBalanceBond', $reportCurrencyId);
                $out += $Bond['lender'];
            }
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
        }
        foreach ($gainDiscountBonds as $Bond) {
            $Bond['name'] = 'سند حسم مكتسب';
            $Bond['lender'] = 0;
            $Bond['debtor'] = $this->convertBondCurrency($Bond, 'GainDiscountBond', $reportCurrencyId);
            $Bond['statement'] = 'سند حسم مكتسب';
            $Bond['exchanges'] =  $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            unset($Bond['amount']);
            $in += $Bond['debtor'];
        }

        // جمع جميع المصفوفات في مصفوفة واحدة
        $data = array_merge(
            $purchaseInvoices->toArray(),
            $returnPurchaseInvoices->toArray(),
            $receiptBonds->toArray(),
            $payBonds->toArray(),
            $openingBalanceBonds->toArray(),
            $gainDiscountBonds->toArray(),
        );


        // if (!empty($categoryId)) {

        //     $data = array_merge(
        //         $purchaseInvoices->toArray(),
        //         $returnPurchaseInvoices->toArray(),
        //     );
        // }

        $lastIndex = null;
        foreach ($data as $index => $item) {
            if ($item['statement'] == 'نقدي' || $item['statement'] == 'بنك') {
                $duplicate = $item;
                $duplicate['debtor'] = $item['lender'];
                $duplicate['lender'] = $item['debtor'];

                array_splice($data, $lastIndex + 1, 0, [$duplicate]);

                $lastIndex++;

                if ($item['name'] == 'فاتورة مرتجع شراء') {
                    $out += $item['debtor'];
                } elseif ($item['name'] == 'فاتورة شراء') {
                    $in += $item['lender'];
                }
            } else {
                $lastIndex = $index;
            }
        }

        $balance = $in - $out;
        $data = [
            'supplier_balance' => $balance,
            'data' => $data,
        ];
        return $data;
    }


    /**
     * تحويل رصيد الصندوق
     * 
     * تقوم هذه الدالة بتحويل رصيد الصندوق من عملة معينة إلى عملة التقرير المطلوبة. 
     * إذا كانت عملة التقرير مختلفة عن عملة الصندوق، سيتم حساب الرصيد بناءً على سعر الصرف الأحدث بين العملتين.
     *
     * @param int $boxCurrencyId معرف عملة الصندوق
     * @param float $boxBalance رصيد الصندوق
     * @param int $reportCurrencyId معرف عملة التقرير
     * @return float الرصيد المحول إلى عملة التقرير
     */
    public function convertBoxBalance($boxCurrencyId, $boxBalance, $reportCurrencyId)
    {
        // الحصول على معرف العملة الافتراضية
        $defaultCurrencyId = Currency::where('is_default', 1)->value('id');

        // إذا كانت عملة التقرير مختلفة عن عملة الصندوق
        if ($reportCurrencyId != $boxCurrencyId) {
            // إذا كانت عملة التقرير هي العملة الافتراضية
            if ($reportCurrencyId == $defaultCurrencyId) {
                $ex = Exchange::where('currency_id', $boxCurrencyId)->latest()->value('value');
                $boxBalance *= $ex;
                // إذا كانت عملة الصندوق هي العملة الافتراضية
            } elseif ($boxCurrencyId == $defaultCurrencyId) {
                $ex = Exchange::where('currency_id', $reportCurrencyId)->latest()->value('value');
                $boxBalance /= $ex;
                // إذا كانت كلا العملتين ليست العملة الافتراضية
            } else {
                $boxEx = Exchange::where('currency_id', $boxCurrencyId)->latest()->value('value');
                $reportEx = Exchange::where('currency_id', $reportCurrencyId)->latest()->value('value');
                $boxBalance = ($boxBalance * $boxEx) / $reportEx;
            }
        }

        // إرجاع الرصيد المحول
        return $boxBalance;
    }




    /**
     * حساب رصيد الصندوق
     * 
     * تقوم هذه الدالة بحساب رصيد الصندوق خلال فترة زمنية محددة عبر جمع الفواتير والسندات المختلفة المرتبطة بالصندوق.
     * يتم احتساب المقبوضات والمدفوعات لتحليل الرصيد النهائي.
     *
     * @param Request $request
     * @return array تحتوي على بيانات الفواتير والسندات بالإضافة إلى الرصيد النهائي
     */
    public function boxBalance(Request $request)
    {
        // الحصول على معرف الصندوق والتواريخ من الطلب
        $boxId = $request->input('box_id');
        $box = Box::find($boxId);
        $categoryId = $request->input('category_id');
        $fromDate = $request->input('from_date');
        $toDate = !empty($request->input('to_date')) ? $request->input('to_date') : date('Y-m-d', strtotime(now()));
        // إعداد الاستعلامات لكل نوع من الفواتير والسندات
        $query1 = PurchaseInvoice::with(['box', 'currency'])->where('box_id', $boxId);
        $query2 = ReturnPurchaseInvoice::with(['box', 'currency'])->where('box_id', $boxId);
        $query3 = SaleInvoice::with(['box', 'currency'])->where('box_id', $boxId);
        $query4 = ReturnSaleInvoice::with(['box', 'currency'])->where('box_id', $boxId);
        $query5 = ReceiptBond::with(['box', 'currency'])->where('box_id', $boxId);
        $query6 = PayBond::with(['box', 'currency'])->where('box_id', $boxId);
        $query7 = MoneyTransferBond::with(['externalBox', 'incomingBox'])
        ->where(function ($query) use ($boxId) {
            $query->where('box_id_external', $boxId)
                ->orWhere('box_id_incoming', $boxId);
        });
        $query8 = ExpensesBond::with(['box', 'currency'])->where('box_id', $boxId);
        $query9 = PersonalExpensesBond::with(['box', 'currency'])->where('box_id', $boxId);
        $query10 = UserMoneyTransferBond::with(['box'])->where('box_id', $boxId);
        $query11 = BoxBalanceBond::with(['box', 'currency'])->where('box_id', $boxId);
        $query12 = BankMoneyTransferBond::with(['fromBox', 'toBox'])
        ->where(function ($query) use ($boxId) {
            $query->where('from_box_id', $boxId)
                ->orWhere('to_box_id', $boxId);
        });
        $query13 = PartnersExpensesBond::with(['box', 'currency'])->where('box_id', $boxId);
        $query14 = PartnerPayBond::with(['box', 'currency'])->where('box_id', $boxId);

        // إذا تم تحديد تواريخ
        if (!empty($fromDate) && !empty($toDate)) {
            $query1->whereBetween('date', [$fromDate, $toDate]);
            $query2->whereBetween('date', [$fromDate, $toDate]);
            $query3->whereBetween('date', [$fromDate, $toDate]);
            $query4->whereBetween('date', [$fromDate, $toDate]);
            $query5->whereBetween('date', [$fromDate, $toDate]);
            $query6->whereBetween('date', [$fromDate, $toDate]);
            $query7->whereBetween('date', [$fromDate, $toDate]);
            $query8->whereBetween('date', [$fromDate, $toDate]);
            $query9->whereBetween('date', [$fromDate, $toDate]);
            $query10->whereBetween('date', [$fromDate, $toDate]);
            $query11->whereBetween('date', [$fromDate, $toDate]);
            $query12->whereBetween('date', [$fromDate, $toDate]);
            $query13->whereBetween('date', [$fromDate, $toDate]);
            $query14->whereBetween('date', [$fromDate, $toDate]);
        }

        if (!empty($categoryId)) {

            $category = Category::find($categoryId);
            $childCategories = $category->allChildren()->pluck('id')->toArray();
            $childCategories[] = $categoryId;
            // تطبيق فلتر على الفواتير و سند المصروف بناءً على فئات المنتجات

            $query1->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query2->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query3->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query4->whereHas('products', function ($subQuery) use ($childCategories) {
                $subQuery->whereIn('category_id', $childCategories);
            });

            $query8->whereIn('category_id', $childCategories);
        }

        // الحصول على البيانات من الاستعلامات
        $purchaseInvoices = $query1->get(['id', 'date', 'code', 'box_id', 'currency_id', 'total_net_price']);
        $returnPurchaseInvoices = $query2->get(['id', 'date', 'code', 'box_id', 'currency_id', 'total_net_price']);
        $saleInvoices = $query3->get(['id', 'code', 'date', 'box_id', 'currency_id', 'total_net_price', 'created_at']);
        $returnSaleInvoices = $query4->get(['id', 'date', 'code', 'box_id', 'currency_id', 'total_net_price']);
        $receiptBonds = $query5->get(['id', 'date', 'code', 'box_id', 'currency_id', 'amount']);
        $payBonds = $query6->get(['id', 'date', 'code', 'box_id', 'currency_id', 'amount']);
        $moneyTransferBonds = $query7->get(['id', 'date', 'code', 'incoming_amount', 'external_amount', 'box_id_external', 'box_id_incoming']);
        $expensesBonds = $query8->get(['id', 'date', 'code', 'box_id', 'currency_id', 'amount']);
        $personalExpensesBonds = $query9->get(['id', 'date', 'code', 'box_id', 'currency_id', 'amount']);
        $userMoneyTransferBonds = $query10->get(['id', 'date', 'code', 'box_id', 'amount', 'sale_invoice_id']);
        $boxBalanceBonds = $query11->get(['id', 'code', 'date', 'box_id', 'currency_id', 'amount']);
        $bankMoneyTransferBonds = $query12->with([
            'fromBox' => function ($q) {
                $q->with('currency:id,name')->select('id', 'name', 'currency_id');
            }
        ])->get(['id', 'code', 'date', 'from_box_id', 'to_box_id', 'amount']);

        // متغيرات لحساب المدفوعات والمقبوضات
        $in = 0;
        $out = 0;
        $partnersExpensesBonds = $query13->get(['id', 'date', 'code', 'box_id', 'currency_id', 'amount']);
        $partnerPayBonds = $query14->get(['id', 'date', 'code', 'box_id', 'currency_id', 'amount']);

        // معالجة فواتير الشراء
        foreach ($purchaseInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['statement'] = 'فاتورة شراء';
            $Invoice['name'] = 'Purchase';
            $Invoice['payments'] = $Invoice->total_net_price; // المدفوعات
            $Invoice['receipts'] = 0; // المقبوضات
            $Invoice['exchanges'] = $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $out += $Invoice['payments'];
        }

        // معالجة فواتير مرتجع الشراء
        foreach ($returnPurchaseInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['statement'] = 'فاتورة مرتجع شراء';
            $Invoice['name'] = 'ReturnPurchase';
            $Invoice['payments'] = 0; // المدفوعات
            $Invoice['receipts'] = $Invoice->total_net_price; // المقبوضات
            $Invoice['exchanges'] = $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $in += $Invoice['receipts'];
        }

        // معالجة فواتير المبيع
        $saleInvoicesMoneyTransferBonds = [];
        foreach ($saleInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['statement'] = 'فاتورة مبيع';
            $Invoice['name'] = 'Sale';
            $Invoice['payments'] = 0; // المدفوعات
            $Invoice['receipts'] = $Invoice->total_net_price; // المقبوضات
            $in += $Invoice['receipts'];
            $Invoice['exchanges'] = $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);

            // إضافة سندات الحوالة بحال كان الصندوق لشركة حوالة و نوع الفاتورة حوالة
            if($box->moneyTransfersCompany){
                if ($Invoice->moneyTransferBonds) {
                    foreach($Invoice->moneyTransferBonds as $bond){
                        $saleInvoicesMoneyTransferBonds[] = $bond;
                        $out += $bond->amount;
                    }
                }
            }
        }

        if($saleInvoicesMoneyTransferBonds){
            foreach($saleInvoicesMoneyTransferBonds as $bond){
                $bond['statement'] = 'سند حوالة';
                $bond['name'] = 'UserMoneyTransferBonds';
                $bond['payments'] = $bond->amount;
                $bond['receipts'] = 0;
                $userMoneyTransferBonds[] = $bond;
            }
        }

        // معالجة فواتير مرتجع المبيع
        foreach ($returnSaleInvoices as $Invoice) {
            if (!empty($categoryId)) {

                // تصفية المنتجات بناءً على فئات الأطفال
                $filteredProducts = $Invoice->products->filter(function ($product) use ($childCategories) {
                    return in_array($product->category_id, $childCategories);
                });

                // مجموع صافي الأسعار للمنتجات المفلترة
                $filteredTotalNetPrice = $filteredProducts->sum(function ($product) {
                    return $product->pivot->net_price;
                });

                // تطبيق الخصم بالنسبة المئوية إذا كان موجودًا
                if (isset($Invoice->percent_discount) && $Invoice->percent_discount != 0) {
                    $discountMultiplier = (100 + $Invoice->percent_discount) / 100;
                    $filteredTotalNetPrice *= $discountMultiplier;
                }

                // تحديث صافي السعر الإجمالي للفاتورة بعد التصفية
                $Invoice->total_net_price = $filteredTotalNetPrice;
            }
            $Invoice['name'] = 'ReturnSale';
            $Invoice['statement'] = 'فاتورة مرتجع مبيع';
            $Invoice['payments'] = $Invoice->total_net_price; // المدفوعات
            $Invoice['receipts'] = 0; // المقبوضات
            $Invoice['exchanges'] = $Invoice->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $out += $Invoice['payments'];
        }

        // معالجة سندات القبض
        foreach ($receiptBonds as $Bond) {
            $Bond['name'] = 'ReceiptBond';
            $Bond['statement'] = 'سند قبض';
            $Bond['payments'] = 0; // المدفوعات
            $Bond['receipts'] = $Bond->amount; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $in += $Bond['receipts'];
        }

        // معالجة سندات الدفع
        foreach ($payBonds as $Bond) {
            $Bond['name'] = 'PayBond';
            $Bond['statement'] = 'سند دفع';
            $Bond['payments'] = $Bond->amount; // المدفوعات
            $Bond['receipts'] = 0; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $out += $Bond['payments'];
        }

        // معالجة سندات التحويل
        foreach ($moneyTransferBonds as $Bond) {
            $Bond['name'] = 'MoneyTransferBond';
            $Bond['statement'] = 'سند تحويل';

            if ($Bond->box_id_external == $boxId) {
                $Bond['payments'] = $Bond->external_amount; // المدفوعات
                $Bond['receipts'] = 0; // المقبوضات
                $out += $Bond['payments'];
            } else {
                $Bond['payments'] = 0;
                $Bond['receipts'] = $Bond->incoming_amount;
                $in += $Bond['receipts'];
            }
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
        }

        // معالجة سندات المصروفات
        foreach ($expensesBonds as $Bond) {
            $Bond['name'] = 'ExpensesBonds';
            $Bond['statement'] = 'سند مصروفات';
            $Bond['payments'] = $Bond->amount; // المدفوعات
            $Bond['receipts'] = 0; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $out += $Bond['payments'];
        }

        // معالجة سندات المسحوبات الشخصية
        foreach ($personalExpensesBonds as $Bond) {
            $Bond['statement'] = 'سند مسحوبات شخصية';
            $Bond['name'] = 'PersonalExpensesBonds';
            $Bond['payments'] = $Bond->amount; // المدفوعات
            $Bond['receipts'] = 0; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $out += $Bond['payments'];
        }

        // معالجة سندات حوالة المستخدم
        if(!$box->moneyTransfersCompany){
            foreach ($userMoneyTransferBonds as $Bond) {
                $Bond['name'] = 'UserMoneyTransferBonds';
                $Bond['statement'] = 'سند حوالة';
                $Bond['payments'] = 0; // المدفوعات
                $Bond['receipts'] = $Bond->amount; // المقبوضات
                $in += $Bond['receipts'];
            }
        }

        // معالجة سندات رصيد الصندوق
        foreach ($boxBalanceBonds as $Bond) {
            $Bond['name'] = 'BoxBalanceBonds';
            $Bond['statement'] = 'سند رصيد صندوق';
            $Bond['payments'] = 0; // المدفوعات
            $Bond['receipts'] = $Bond->amount; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $in += $Bond['receipts'];
        }

        // معالجة سندات تحويل البنوك
        foreach ($bankMoneyTransferBonds as $Bond) {
            $Bond['name'] = 'BankMoneyTransferBond';
            $Bond['statement'] = 'سند تحويل بنك';

            if ($Bond->from_box_id == $boxId) {
                $Bond['payments'] = $Bond->amount; // المدفوعات
                $Bond['receipts'] = 0; // المقبوضات
                $out += $Bond['payments'];
            } else {
                $Bond['payments'] = 0;
                $Bond['receipts'] = $Bond->amount;
                $in += $Bond['receipts'];
            }
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
        }

        // معالجة سندات مسحوبات الشركاء
        foreach ($partnersExpensesBonds as $Bond) {
            $Bond['name'] = 'PartnersExpensesBond';
            $Bond['statement'] = 'سند مسحوبات الشركاء';
            $Bond['payments'] = $Bond->amount; // المدفوعات
            $Bond['receipts'] = 0; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $out += $Bond['payments'];
        }

        // معالجة سندات مدفوعات الشركاء
        foreach ($partnerPayBonds as $Bond) {
            $Bond['name'] = 'PartnerPayBond';
            $Bond['statement'] = 'سند مدفوعات الشركاء';
            $Bond['payments'] = 0; // المدفوعات
            $Bond['receipts'] = $Bond->amount; // المقبوضات
            $Bond['exchanges'] = $Bond->exchanges()->with('currency:id,name')->get(['id', 'currency_id', 'exchange']);
            $in += $Bond['receipts'];
        }

        // جمع جميع المصفوفات في مصفوفة واحدة
        $data = array_merge(
            $purchaseInvoices->toArray(),
            $returnPurchaseInvoices->toArray(),
            $saleInvoices->toArray(),
            $returnSaleInvoices->toArray(),
            $receiptBonds->toArray(),
            $payBonds->toArray(),
            $moneyTransferBonds->toArray(),
            $expensesBonds->toArray(),
            $personalExpensesBonds->toArray(),
            $userMoneyTransferBonds->toArray(),
            $boxBalanceBonds->toArray(),
            $bankMoneyTransferBonds->toArray(),
            $partnersExpensesBonds->toArray(),
            $partnerPayBonds->toArray(),
        );

        // حساب الرصيد النهائي
        $balance = $in - $out;

        // إرجاع البيانات والرصيد النهائي
        return ['data' => $data, 'balance' => $balance];
    }


    /**
     * الحصول على حركة المنتج من الفواتير
     *
     * هذه الدالة تقوم بجلب ومعالجة حركة المنتج من خلال الفواتير المرتبطة به،
     * بما في ذلك فواتير الشراء، فواتير مرتجع الشراء، فواتير المبيع، وفواتير مرتجع المبيع،
     * بالإضافة إلى فواتير بضاعة أول المدة.
     *
     * @param Product $product
     * @return array تحتوي على بيانات الفواتير المختلفة وحركة المنتج
     */
    public function productMovementInvoices($product)
    {
        // الحصول على فواتير الشراء ومعالجتها
        $purchaseInvoices = $product->purchaseInvoices()->with(
            [
                'supplier' => function ($query) {
                    $query->select('name', 'id');
                },
                'customer' => function ($query) {
                    $query->select('name', 'id');
                },
            ]
        )->get(['purchase_invoice_id', 'date', 'code', 'supplier_id', 'customer_id', 'quantity']);
        $purchaseInvoices = $purchaseInvoices->makeHidden('pivot');

        foreach ($purchaseInvoices as $invoice) {
            $invoice['entries'] = $invoice->quantity;
            $invoice['statement'] = 'فاتورة شراء';
            $invoice['outputs'] = 0;
            $invoice['balance'] = 0;

            if ($invoice->supplier) {
                $invoice['name'] = 'المورد: ' . $invoice->supplier->name;
            }
            if ($invoice->customer) {
                $invoice['name'] = 'الزبون: ' . $invoice->customer->name;
            }
            unset(
                $invoice->supplier,
                $invoice->customer,
                $invoice->quantity,
                $invoice->supplier_id,
                $invoice->customer_id
            );
        }

        // الحصول على فواتير مرتجع الشراء ومعالجتها
        $returnPurchaseInvoices = $product->returnPurchaseInvoices()->with(
            [
                'supplier' => function ($query) {
                    $query->select('name', 'id');
                },
                'customer' => function ($query) {
                    $query->select('name', 'id');
                },
            ]
        )->get(['return_purchase_invoice_id', 'date', 'code', 'supplier_id', 'customer_id', 'quantity']);
        $returnPurchaseInvoices = $returnPurchaseInvoices->makeHidden('pivot');

        foreach ($returnPurchaseInvoices as $invoice) {
            $invoice['entries'] = 0;
            $invoice['outputs'] = $invoice->quantity;
            $invoice['balance'] = 0;
            $invoice['statement'] = 'فاتورة مرتجع شراء';

            if ($invoice->supplier) {
                $invoice['name'] = 'المورد: ' . $invoice->supplier->name;
            }
            if ($invoice->customer) {
                $invoice['name'] = 'الزبون: ' . $invoice->customer->name;
            }
            unset(
                $invoice->supplier,
                $invoice->customer,
                $invoice->quantity,
                $invoice->supplier_id,
                $invoice->customer_id
            );
        }

        // الحصول على فواتير المبيع ومعالجتها
        $saleInvoices = $product->saleInvoices()->with(
            [
                'customer' => function ($query) {
                    $query->select('name', 'id');
                },
            ]
        )->get(['sale_invoice_id', 'date', 'code', 'customer_id', 'quantity']);
        $saleInvoices = $saleInvoices->makeHidden('pivot');

        foreach ($saleInvoices as $invoice) {
            $invoice['entries'] = 0;
            $invoice['outputs'] = $invoice->quantity;
            $invoice['balance'] = 0;
            $invoice['name'] = 'الزبون: ' . $invoice->customer->name;
            $invoice['statement'] = 'فاتورة مبيع';

            unset(
                $invoice->customer,
                $invoice->quantity,
                $invoice->customer_id
            );
        }

        // الحصول على فواتير مرتجع المبيع ومعالجتها
        $returnSaleInvoices = $product->returnSaleInvoices()->with(
            [
                'customer' => function ($query) {
                    $query->select('name', 'id');
                },
            ]
        )->get(['return_sale_invoice_id', 'date', 'code', 'customer_id', 'quantity']);
        $returnSaleInvoices = $returnSaleInvoices->makeHidden('pivot');

        foreach ($returnSaleInvoices as $invoice) {
            $invoice['entries'] = $invoice->quantity;
            $invoice['outputs'] = 0;
            $invoice['balance'] = 0;
            $invoice['name'] = 'الزبون: ' . $invoice->customer->name;
            $invoice['statement'] = 'فاتورة مرتجع مبيع';

            unset(
                $invoice->customer,
                $invoice->quantity,
                $invoice->customer_id
            );
        }

        // الحصول على فواتير بضاعة أول المدة ومعالجتها
        $firstTermInvoices = $product->firstTermInvoices()->get(['first_term_invoice_id', 'date', 'code', 'quantity']);
        $firstTermInvoices = $firstTermInvoices->makeHidden('pivot');

        foreach ($firstTermInvoices as $invoice) {
            $invoice['entries'] = $invoice->quantity;
            $invoice['outputs'] = 0;
            $invoice['balance'] = 0;
            $invoice['name'] = '-';
            $invoice['statement'] = 'بضاعة أول المدة';
            unset($invoice['quantity']);
        }
        $firstTermInvoices = $firstTermInvoices->sortByDesc('date');

        // تحويل الفواتير إلى مصفوفات
        $purchaseInvoicesArray = $purchaseInvoices->map(function ($invoice) {
            return $invoice->toArray();
        })->all();

        $returnPurchaseInvoicesArray = $returnPurchaseInvoices->map(function ($invoice) {
            return $invoice->toArray();
        })->all();

        $saleInvoicesArray = $saleInvoices->map(function ($invoice) {
            return $invoice->toArray();
        })->all();

        $returnSaleInvoicesArray = $returnSaleInvoices->map(function ($invoice) {
            return $invoice->toArray();
        })->all();

        // دمج جميع الفواتير في مصفوفة واحدة
        $invoices = array_merge($purchaseInvoicesArray, $returnPurchaseInvoicesArray, $saleInvoicesArray, $returnSaleInvoicesArray);

        // ترتيب الفواتير حسب التاريخ
        usort($invoices, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // إعداد البيانات النهائية
        $data = [
            'firstTermInvoices' => $firstTermInvoices,
            'invoices' => $invoices,
        ];

        // إرجاع البيانات النهائية
        return $data;
    }


    /**
     * تحويل سعر المنتج إذا كان بعملة غير عملته
     *
     * هذه الدالة تقوم بتحويل سعر المنتج إذا كانت عملة الفاتورة غير عملته
     *
     * @param float $price سعر المنتج في الفاتورة
     * @param int $productId معرف المنتج
     * @param int $productInvoiceId معرف الفاتورة
     * @param string $productInvoiceType نوع الفاتورة
     * @return float السعر المحول
     */
    public function convertProductPrice($price, $productId, $productInvoiceId, $productInvoiceType)
    {
        // جلب المنتج بناءً على معرف المنتج
        $product = Product::find($productId);

        // تحديد نوع الفاتورة وجلب الفاتورة بناءً على المعرف والنوع
        $invoiceClassName = "App\\Models\\$productInvoiceType";
        $invoice = new $invoiceClassName();
        $invoice = $invoice->find($productInvoiceId);

        // التحقق من اختلاف العملة بين المنتج والفاتورة
        if ($product->currency_id != $invoice->currency_id) {
            // في حالة كانت عملة الفاتورة هي العملة الافتراضية
            if ($invoice->currency->is_default) {
                $ex = InvoicesExchange::where('invoicesable_id', $productInvoiceId)
                    ->where('currency_id', $product->currency_id)
                    ->where('invoicesable_type', $productInvoiceType)
                    ->value('exchange');
                $price /= $ex;

                // في حالة كانت عملة المنتج هي العملة الافتراضية
            } elseif ($product->currency->is_default) {
                $ex = InvoicesExchange::where('invoicesable_id', $productInvoiceId)
                    ->where('currency_id', $invoice->currency_id)
                    ->where('invoicesable_type', $productInvoiceType)
                    ->value('exchange');
                $price *= $ex;

                // في حالة كانت كل من عملة الفاتورة وعملة المنتج ليستا العملة الافتراضية
            } else {
                $productEx = InvoicesExchange::where('invoicesable_id', $productInvoiceId)
                    ->where('currency_id', $product->currency_id)
                    ->where('invoicesable_type', $productInvoiceType)
                    ->value('exchange');
                $invoiceEx = InvoicesExchange::where('invoicesable_id', $productInvoiceId)
                    ->where('currency_id', $invoice->currency_id)
                    ->where('invoicesable_type', $productInvoiceType)
                    ->value('exchange');

                $price = ($price * $invoiceEx) / $productEx;
            }
        }

        // إرجاع السعر المحول
        return $price;
    }
}
