<?php

namespace App\Traits;

use App\Http\Controllers\Reports\BoxReportController;
use App\Models\BondsExchange;
use App\Models\Box;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\InvoicesExchange;
use App\Models\Product;
use App\Models\Storehouse;
use App\Models\Supplier;
use App\Models\Tathbeet;
use Illuminate\Http\Request;

trait resourceDataTrait
{
    use InvoicesTrait;

    /**
     * استرجاع قائمة المنتجات بناءً على معايير البحث المحددة.
     *
     * @param \Illuminate\Http\Request $request الطلب الذي يحتوي على معايير البحث.
     * 
     * @return \Illuminate\Pagination\LengthAwarePaginator قائمة المنتجات مع تفاصيلها، مشمولة ببيانات التصنيف، العملة، الوحدة، السعر، الصور، والمعلومات عن من أنشأها، بالإضافة إلى الكميات المتبقية في المخازن.
     */
    public function getProducts($request)
    {
        $searchName = $request->input('name');
        $searchCode = $request->input('code');
        $searchCategory = $request->input('category_id');
        $searchCurrency = $request->input('currency');
        $searchBarcode = $request->input('barcode');
        $searchDescription = $request->input('description');
        $searchModel = $request->input('model');
        $date = $request->input('date');

        $query = Product::with([
            'category' => function ($query) {
                $query->select('id', 'name');
            },
            'currency',
            'unit',
            'price'=> function ($query) {
                $query->select('id', 'price_type_id', 'value', 'product_id')->with(['priceType:id,name']);
            },
            'images',
            'createdBy' => function ($query) {
                $query->select('id', 'first_name', 'last_name');
            },
        ]);

        if (!empty($searchName) || !empty($searchCode) || !empty($searchCategory) || !empty($searchCurrency) || !empty($searchBarcode) || !empty($searchDescription) || !empty($searchModel)) {
            $query->where(function ($q) use ($searchName, $searchCode, $searchCategory, $searchCurrency, $searchBarcode, $searchDescription, $searchModel) {
                if (!empty ($searchName)) {
                    $q->where('name', 'like', '%' . $searchName . '%');
                }
                if (!empty ($searchCode)) {
                    $q->where('code', 'like', '%' . $searchCode . '%');
                }
                if (!empty($searchCategory)) {
                    $category = Category::find($searchCategory);
                        $childCategories = $category->allChildren()->pluck('id')->toArray();
                        $childCategories[] = $category->id;
                        $q->whereIn('category_id', $childCategories);                    
                }
                if (!empty ($searchCurrency)) {
                    $q->whereHas('currency', function ($query) use ($searchCurrency) {
                        $query->where('name', 'like', '%' . $searchCurrency . '%');
                    });
                }
                if (!empty ($searchBarcode)) {
                    $q->where('barcode', 'like', '%' . $searchBarcode . '%');
                }
                if (!empty ($searchDescription)) {
                    $q->where('description', 'like', '%' . $searchDescription . '%');
                }
                if (!empty ($searchModel)) {
                    $q->where('model', 'like', '%' . $searchModel . '%');
                }
            });
        }

        $products = $query->paginate(50);
        $storehouses = Storehouse::all();

        $groupedData = [];
        foreach($products as $pro)
        {
            $qtyData = []; 

            foreach ($storehouses as $storehouse) {
                $remainQty = $this->validateProductQty($pro->id, $storehouse->id, $date);
                $remainQty = $remainQty['remainQty'];
                $qtyData[] = [
                    'storehouse_id' => $storehouse->id,
                    'remainQty' => $remainQty,
                ];
            }

            $pro['storehouses_quantities'] = $qtyData;

            // dd($pro->price);

            // if (!isset($groupedData[$pro->id])) {
            //     $groupedData[$pro->id] = [
            //         'prices' => [],
            //     ];
            // }

            // $groupedData[$pro->id]['prices'][] = [
            //     'id' => $pro->price->id,
            //     'price_type' => $pro->price->priceType->name,
            //     'price_type_id' => $pro->price->priceType->id,
            //     'value' => $pro->price->value,
            // ];
        }
        // $products->setCollection(collect(array_values($groupedData)));


        return $products;
        

    }

    /**
     * استرجاع قائمة "التثبيتات" بناءً على معايير البحث المحددة.
     *
     * @param \Illuminate\Http\Request $request الطلب الذي يحتوي على معايير البحث.
     * 
     * @return \Illuminate\Pagination\LengthAwarePaginator قائمة "التثبيتات" مع تفاصيلها، بما في ذلك العملة، الفئة، المورد، الوحدة، العميل، والمعلومات عن من أنشأها، بالإضافة إلى الكميات المتبقية والقيم، وقيم سندات الدفع، ومعرفات الفئات الفرعية.
     */
    public function getTathbeets($request)
    {
        $searchNumber = $request->input('number');
        $searchSupplier = $request->input('supplier');
        $searchCategory = $request->input('category');
        $date = $request->input('date');

        $query = Tathbeet::with([
            'currency',
            'category' => function ($query) {
                $query->select('id', 'code', 'name');
            },
            'supplier' => function ($query) {
                $query->select('id', 'code', 'name');
            },
            'unit' => function ($query) {
                $query->select('id', 'name');
            },
            'customer' => function ($query) {
                $query->select('id', 'name');
            },
            'createdBy' => function ($query) {
                $query->select('id', 'first_name', 'last_name');
            },
        ]);

        if ($searchNumber || $searchSupplier || $searchCategory) {
            $query->where(function ($q) use ($searchNumber, $searchCategory, $searchSupplier) {
                if (!empty ($searchNumber)) {
                    $q->where('number', 'like', '%' . $searchNumber . '%');
                }
                if (!empty ($searchCategory)) {
                    $q->whereHas('category', function ($query) use ($searchCategory) {
                        $query->where('name', 'like', '%' . $searchCategory . '%');
                    });
                }
                if (!empty ($searchSupplier)) {
                    $q->whereHas('supplier', function ($query) use ($searchSupplier) {
                        $query->where('name', 'like', '%' . $searchSupplier . '%');
                    });
                }
            });
        }

        $tathbeets = $query->paginate(10);

        foreach($tathbeets as $tathbeet)
        {
            $data = $this->getTathbeetRemains($tathbeet->id, $date);
            $tathbeet['remain_quantities'] = $data['remain_quantities'];
            $tathbeet['remain_values'] = $data['remain_values'];
            $tathbeet['remain_pay_bonds_value'] = $data['remain_pay_bonds_value'];
            $tathbeetCategory = Category::find($tathbeet['category']['id']);
            $childCategoriesIDs = $tathbeetCategory->allChildren()->pluck('id')->toArray();
            $childCategoriesIDs[] = $tathbeet['category']['id'];
            $tathbeet['category']['children_IDs'] = $childCategoriesIDs;
        }

        return $tathbeets;
    }

    /**
     * استرجاع قائمة الموردين بناءً على معايير البحث المحددة.
     *
     * @param \Illuminate\Http\Request $request الطلب الذي يحتوي على معايير البحث.
     * 
     * @return \Illuminate\Pagination\LengthAwarePaginator قائمة الموردين التي تطابق معايير البحث.
     */
    public function getSuppliers($request)
    {
        $searchName = $request->input('name');

        $query = Supplier::with([
            'category' => function ($query) {
                $query->select('id', 'code', 'name');
            },
            'currency' => function ($query) {
                $query->select('id', 'name', 'is_default', 'type');
            },
        ])->where(function ($query) use ($searchName) {
            if (!empty ($searchName)) {
                $query->where('name', 'like', '%' . $searchName . '%');
            }
        });

        return $query->paginate(10);
    }


    /**
     * استرجاع قائمة عملاء مفهرسة بناءً على معايير البحث.
     *
     * تقوم هذه الدالة بتنفيذ بحث على نموذج `Customer` باستخدام معايير البحث المقدمة من الطلب.
     * تشمل البيانات المتعلقة بالفئات والعملات وأنواع الأسعار.
     *
     * @param \Illuminate\Http\Request $request الطلب HTTP الذي يحتوي على معايير البحث.
     * 
     * @return \Illuminate\Pagination\LengthAwarePaginator قائمة عملاء مفهرسة تتطابق مع معايير البحث.
     */
    public function getCustomers($request)
    {
        $searchName = $request->input('name');

        $query = Customer::with([
            'category' => function ($query) {
                $query->select('id', 'name', 'code');
            },
            'currency' => function ($query) {
                $query->select('id', 'name', 'is_default', 'type');
            },
            'priceType' => function ($query) {
                $query->select('id', 'name');
            },
        ])->where(function ($query) use ($searchName) {
            if (!empty ($searchName)) {
                $query->where('name', 'like', '%' . $searchName . '%');
            }
        });

        return $query->paginate(10);
    }


    /**
     * استرجاع قائمة العملاء والموردين بناءً على معايير البحث.
     *
     * تقوم هذه الدالة بتنفيذ بحث على نموذج "Customer" ونموذج "Supplier" باستخدام معايير البحث المقدمة من الطلب.
     * يتم دمج نتائج العملاء والموردين في مجموعة واحدة مع تصنيف كل عنصر كـ "زبون" أو "مورد".
     *
     * @param \Illuminate\Http\Request $request الطلب HTTP الذي يحتوي على معايير البحث.
     * 
     * @return \Illuminate\Pagination\LengthAwarePaginator قائمة العملاء والموردين المفهرسة التي تتطابق مع معايير البحث.
     */
    public function getCustomersAndSuppliers($request)
    {
        $searchName = $request->input('name');

        $customersQuery = Customer::with([
            'category' => function ($query) {
                $query->select('id', 'name', 'code');
            },
            'currency' => function ($query) {
                $query->select('id', 'name', 'is_default', 'type');
            },
            'priceType' => function ($query) {
                $query->select('id', 'name');
            },
        ])->where(function ($query) use ($searchName) {
            if (!empty($searchName)) {
                $query->where('name', 'like', '%' . $searchName . '%');
            }
        })->select('name', 'id', 'category_id', 'currency_id', 'price_type_id', 'code')
            ->selectRaw("'زبون' as type");

        $suppliersQuery = Supplier::with([
            'category' => function ($query) {
                $query->select('id', 'code', 'name');
            },
            'currency' => function ($query) {
                $query->select('id', 'name','is_default', 'type');
            },
        ])->where(function ($query) use ($searchName) {
            if (!empty($searchName)) {
                $query->where('name', 'like', '%' . $searchName . '%');
            }
        })->select('name', 'id', 'category_id', 'currency_id', 'company', 'code')
            ->selectRaw("'مورد' as type");

        $mixedData = $customersQuery->union($suppliersQuery)->paginate(10);

        return $mixedData;
    }

    /**
     * استرجاع معلومات المنتجات بناءً على قائمة معرفات المنتجات.
     *
     * تقوم هذه الدالة بالبحث عن المنتجات بناءً على معرفاتها المقدمة في الطلب، ثم تقوم بجمع كمية كل منتج في كل مستودع معين بتاريخ محدد.
     * تُضاف المعلومات حول كميات المنتجات في المستودعات إلى نتائج المنتجات.
     *
     * @param \Illuminate\Http\Request $request الطلب HTTP الذي يحتوي على قائمة معرفات المنتجات وتاريخ.
     * 
     * @return array قائمة المنتجات التي تحتوي على معلومات الكمية في كل مستودع.
     */
    public function getProductsByIDs($request)
    {
        $productsIDs = $request->input('products_ids');
        $date = $request->input('date');
        $storehouses = Storehouse::all();

        $products = Product::whereIn('id', $productsIDs)->get();

        $result = [];

        foreach ($products as $product) {
            $qtyData = [];

            foreach ($storehouses as $storehouse) {
                $remainQty = $this->validateProductQty($product->id, $storehouse->id, $date);
                $qtyData[] = [
                    'storehouse_id' => $storehouse->id,
                    'remainQty' => $remainQty['remainQty'],
                ];
            }

            $productData = $product->toArray();
            $productData['storehouses_quantities'] = $qtyData;
            $result[] = $productData;
        }

        return $result;
    }

    /**
     * استرجاع تفاصيل سجل "تثبيت" معين بناءً على المعرف وتاريخ محدد.
     *
     * تقوم هذه الدالة بالبحث عن سجل "تثبيت" معين باستخدام المعرف المقدّم، ثم تجمع بيانات حول الكميات والقيم المتبقية وتفاصيل الفئة.
     * تُضاف المعلومات حول الكميات المتبقية والقيم المتبقية وقيم سندات الدفع المتبقية إلى بيانات السجل.
     *
     * @param \Illuminate\Http\Request $request الطلب HTTP الذي يحتوي على معرف السجل وتاريخ.
     * 
     * @return \App\Models\Tathbeet تفاصيل سجل "تثبيت" مع معلومات الكميات والقيم المتبقية، والفئات.
     */
    public function getTathbeetById($request){
        $tathbeet = Tathbeet::find($request->input('tathbeet_id'));
        $date = $request->input('date');


        $data = $this->getTathbeetRemains($tathbeet->id, $date);
        $tathbeet['remain_quantities'] = $data['remain_quantities'];
        $tathbeet['remain_values'] = $data['remain_values'];
        $tathbeet['remain_pay_bonds_value'] = $data['remain_pay_bonds_value'];
        $tathbeetCategory = Category::find($tathbeet['category']['id']);
        $childCategoriesIDs = $tathbeetCategory->allChildren()->pluck('id')->toArray();
        $childCategoriesIDs[] = $tathbeet['category']['id'];
        $tathbeet['category']['children_IDs'] = $childCategoriesIDs;

        return $tathbeet;
    }

    /**
     * جلب جميع الصناديق مع أرصدتها
     *
     * هذه الدالة تقوم بجلب جميع الصناديق مع العلاقات المرتبطة بها (العملة والبنك).
     * ثم لكل صندوق يتم استدعاء دالة لحساب الرصيد الخاص به باستخدام `BoxReportController`.
     * في النهاية، يتم إرجاع قائمة الصناديق مع الرصيد لكل صندوق.
     *
     * @return \Illuminate\Database\Eloquent\Collection قائمة الصناديق مع الأرصدة
     */
    public function getBoxesWithBalances()
    {
        // جلب جميع الصناديق مع العلاقات المرتبطة (العملة والبنك)
        $boxes = Box::with(['currency:id,name,is_default', 'bank:id,name'])->get();

        // المرور عبر كل صندوق لحساب الرصيد
        foreach ($boxes as $box) {
            // إنشاء كائن جديد من BoxReportController لحساب الرصيد
            $boxController = new BoxReportController();
            $request = new Request();

            // إعداد طلب جديد يحتوي على معرف الصندوق
            $request['box_id'] = $box->id;

            // حساب الرصيد للصندوق الحالي
            $boxController = $boxController->total($request);

            // جلب البيانات الأصلية لنتيجة حساب الرصيد
            $boxControllerData = $boxController->getOriginalContent();

            // تعيين الرصيد للصندوق الحالي
            $box['balance'] = $boxControllerData['data']['balance'];
        }

        // إرجاع قائمة الصناديق مع الأرصدة
        return $boxes;
    }





}
