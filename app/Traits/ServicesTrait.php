<?php

namespace App\Traits;

use App\Constants\MessageConstants;
use App\Http\Controllers\Api\WhatsAppController;
use App\Jobs\DeleteMessageWhatsApp;
use App\Models\BankMoneyTransferBond;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DifferenceBond;
use App\Models\ReceiptBond;
use App\Models\DocumentReceipt;
use App\Models\Exchange;
use App\Models\FirstTermInvoice;
use App\Models\Guide;
use App\Models\MoneyTransferBond;
use App\Models\Order;
use App\Models\PartnerPayBond;
use App\Models\PartnersExpensesBond;
use App\Models\PayBond;
use App\Models\pdfForm;
use App\Models\PersonalExpensesBond;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProjectOrder;
use App\Models\PurchaseInvoice;

use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnSaleInvoice;
use App\Models\SaleInvoice;
use App\Models\StorehouseEquality;
use App\Models\Tathbeet;
use App\Models\Unit;
use App\Models\UserMoneyTransferBond;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use PDF;
use Exception;
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as RequestWhatsapp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

trait ServicesTrait
{
    use FileUploadTrait, ReportTrait;



    ///////////////////////////////// For whatsApp ///////////////////////////////////
    /**
     * إرسال مستند عبر WhatsApp إلى قائمة من المستلمين.
     *
     * تقوم هذه الدالة بإرسال مستند بصيغة PDF إلى مجموعة من الأرقام على WhatsApp. 
     * تتأكد من وجود المستند، ثم تقوم بترميزه وإرساله إلى المستلمين. 
     * بعد إرسال الرسائل، تعالج الدالة الأخطاء وتعيد معلومات حول حالة الإرسال.
     *
     * @param mixed $data البيانات المتعلقة بالمستند، يجب أن تحتوي على المسار النسبي أو المطلق للمستند.
     * @param array $recipientsWhatsApp قائمة الأرقام التي سيتم إرسال الرسائل إليها.
     * @param string|null $filename اسم الملف الذي سيتم إرساله، اختياري.
     * 
     * @return \Illuminate\Http\JsonResponse استجابة JSON تحتوي على حالة الإرسال، مع معلومات حول الأخطاء إن وجدت.
     */
    public function sendWhatsApp($data, $recipientsWhatsApp, $filename = null, $document)
    {
        if (!$data) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }

        $guidesNames = [];
        $errorSend = [];
        $token = env('ULTRAMSG_TOKEN');
        if (!$token) {
            return 0;
        }
        foreach ($recipientsWhatsApp as $recipient) {
            try {
                $params = array(
                    'token' => $token,
                    'to' => $recipient,
                    'filename' => $filename,
                    'document' => base64_encode($document), // Encode the file contents
                    'caption' => '',
                    'priority' => '',
                    'referenceId' => '',
                    'nocache' => '',
                    'msgId' => '',
                    'mentions' => ''
                );

                $client = new \GuzzleHttp\Client();
                $headers = [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ];
                $options = ['form_params' => $params];
                $request = new \GuzzleHttp\Psr7\Request('POST', 'https://api.ultramsg.com/instance88819/messages/document', $headers);

                $client->sendAsync($request, $options)->wait();
                // echo $res->getBody();
            } catch (\Exception $e) {
                $errorSend[] = $recipient;
            }
        }

        // إرسال رسالة توضيحية بنجاح إرسال الرسائل للجميع

        if (!empty($errorSend)) {
            foreach ($errorSend as $recipient) {
                $guide = Guide::where('whatsapp', $recipient)->first();
                if ($guide) {
                    // إرسال رسالة للمستلمين غير المشتركين تدعوهم للانضمام إلى البوت
                    $guidesNames[] = $guide->name . ': لم يشترك في البوت. يرجى الانضمام للبوت ثم المحاولة مرة أخرى.';
                }
            }
            // إرسال رسالة توضيحية بعدم إرسال الرسائل إلى بعض المستلمين
            return $this->apiResponse($guidesNames, 'تم الإرسال للجميع عدا الأشخاص التالية:', 200);
        } else {
            // إرسال رسالة توضيحية بنجاح إرسال الرسائل للجميع
            return $this->apiResponse([], 'تم الإرسال للجميع بنجاح.', 200);
        }
    }

    /**
     * إرسال سند عبر WhatsApp إلى عميل محدد.
     *
     * تقوم هذه الدالة بإرسال سند (قبض أو دفع) عبر WhatsApp إلى عميل معين. 
     * تجمع المعلومات المتعلقة بالسند ورصيد العميل ثم ترسلها عبر API الخاص بـ UltraMsg.
     *
     * @param int $customer_id معرف العميل الذي سيتم إرسال الرسالة إليه.
     * @param int $documentId معرف السند (القبض أو الدفع) الذي سيتم تضمينه في الرسالة.
     * @param string $type نوع السند ('ReceiptBond' أو 'PayBond').
     * @param \Illuminate\Http\Request $request كائن الطلب يحتوي على معلومات إضافية مثل رصيد العميل.
     * 
     * @return \Illuminate\Http\JsonResponse استجابة JSON تحتوي على رسالة الخطأ في حالة حدوث استثناء.
     */
    public function whatsAppBondToCustomer($customer_id, $documentId, $type, $request)
    {
        $customerBalance = $this->customerBalance($request);
        $customerBalance = $customerBalance['customer_balance'];

        switch ($type) {
            case 'ReceiptBond':
                $query = ReceiptBond::find($documentId);
                $name = 'سند القبض';
                break;
            case 'PayBond':
                $query = PayBond::find($documentId);
                $name = 'سند دفع';
                break;
        }
        $text =
            "رقم $name :" . " $query->code \n"
            . "مجموع صافي قلم $name: \n" . "$query->amount\n" . ' ' . $query->currency->name . 
            "=================\n" .
            "  الرصيد:\n" .
            $customerBalance;
        try {

            $token = env('ULTRAMSG_TOKEN');
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
            $whatsAppControllerData = $whatsAppControllerOriginal['data'];
            // الحصول على آخر عنصر في المصفوفة
            $lastMessage = end($whatsAppControllerData);
            DeleteMessageWhatsApp::dispatch($lastMessage['id'])->delay(now()->addDay());
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return $this->apiResponse(null, $message, 500);
        }
    }

    ///////////////////////////////// For PDFs ///////////////////////////////////
    /**
     * إنشاء ملف PDF من محتوى HTML.
     *
     * تقوم هذه الدالة بإنشاء ملف PDF بناءً على محتوى HTML المقدم، وتقوم بإرجاع محتوى PDF إما كاستجابة JSON مشفرة بتنسيق Base64، أو ككائن mPDF بناءً على نوع الإخراج المطلوب.
     *
     * @param string $html محتوى HTML الذي سيتم تحويله إلى PDF.
     * @param string $namePath مسار أو اسم الملف لتخزين ملف PDF (إذا كان نوع الإخراج هو 'S').
     * @param string $type نوع الإخراج المطلوب، إما 'S' لإرجاع محتوى PDF مشفر بتنسيق Base64، أو أي قيمة أخرى لإرجاع كائن mPDF.
     *
     * @return \Illuminate\Http\JsonResponse|Mpdf\Output\OutputInterface إذا كان النوع 'S'، فإن الدالة تُرجع استجابة JSON تحتوي على محتوى PDF مشفر بتنسيق Base64. إذا كان النوع غير 'S'، فإنها تُرجع كائن mPDF.
     */
    public function pdf($html, $namePath, $type = 'S')
    {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'format' => [210, 297],
            'fontDir' => array_merge($fontDirs, [
                public_path('fonts')
            ]),
            'fontdata' => $fontData + [
                'cairo' => [
                    'R' => 'Cairo-Regular.ttf',
                    'B' => 'Cairo-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ]
            ],
            'default_font' => 'cairo'
        ]);

        $mpdf->WriteHTML($html);

        if ($type == 'S') {
            $filename = $namePath . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

            $pdfContent = $mpdf->Output($filename, 'S');
            $pdfContent = base64_encode($pdfContent);
            return response()->json(['pdf' => $pdfContent]);
        } else {
            return $mpdf;
        }
    }


    public function pdfForWhatsapp($html, $namePath,)
    {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'format' => [210, 297],
            'fontDir' => array_merge($fontDirs, [
                public_path('fonts')
            ]),
            'fontdata' => $fontData + [
                'cairo' => [
                    'R' => 'Cairo-Regular.ttf',
                    'B' => 'Cairo-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ]
            ],
            'default_font' => 'cairo'
        ]);

        $mpdf->WriteHTML($html);
        $filename = $namePath . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

        $pdfContent = $mpdf->Output($filename, 'S');
        // $pdfContent = base64_encode($pdfContent);
        return  $pdfContent;
    }

    /**
     * إنشاء ملف PDF بحجم A6 من محتوى HTML.
     *
     * تقوم هذه الدالة بإنشاء ملف PDF بحجم A6 من محتوى HTML المقدم، ثم ترجع محتوى PDF مشفر بتنسيق Base64 كاستجابة JSON. 
     * يتم تعيين هوامش الصفحة إلى صفر، واستخدام خط Cairo كخط افتراضي.
     *
     * @param string $html محتوى HTML الذي سيتم تحويله إلى PDF.
     * @param string $namePath مسار أو اسم الملف لتخزين ملف PDF.
     *
     * @return \Illuminate\Http\JsonResponse استجابة JSON تحتوي على محتوى PDF مشفر بتنسيق Base64.
     */
    public function pdfA6($html, $namePath)
    {

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'format' => [80, 297],
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_left' => 0,
            'margin_right' => 0,
            'fontDir' => array_merge($fontDirs, [
                public_path('fonts'),

            ]),
            'fontdata' => $fontData + [
                'cairo' => [
                    'R' => 'Cairo-Regular.ttf',
                    'B' => 'Cairo-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ]
            ],
            'default_font' => 'cairo'
        ]);

        $mpdf->WriteHTML($html);
        $filename = $namePath . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

        $pdfContent = $mpdf->Output($filename, 'S');

        // Prepend the PDF header (adjust the header based on your PDF library)
        $pdfContent = '%PDF-1.4\n' . $pdfContent;

        // Encode the PDF content to base64
        $pdfContent = base64_encode($pdfContent);

        return response()->json(['pdf' => $pdfContent]);
    }

    /**
     * إنشاء ملف PDF للتثبيت.
     *
     * هذه الدالة تقوم بإنشاء ملف PDF لكيان التثبيت بناءً على العملية المقدمة.
     * إذا كانت العملية 'store' أو 'update'، فإنها تنشئ ملف PDF جديد للتثبيت
     * وتحدث الخاصية 'pdf_path'. إذا كانت العملية 'forceDelete'، فإنها
     * تحذف الملف PDF الموجود المرتبط بالتثبيت.
     *
     * @param int $id معرف كيان التثبيت.
     * @param string $operation العملية المراد القيام بها ('store'، 'update'، أو 'forceDelete').
     * @return void
     */

    public function TathbeetPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/Tathbeets";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $tathbeet = Tathbeet::with([
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
        ])->withTrashed()->find($id);

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $tathbeet->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = ['tathbeet' => $tathbeet];

                $html = view('pdf.tathbeet', $data)->render();
                $mpdf = $this->pdf($html, 'tathbeet', 'D');
                if ($tathbeet->supplier_id)
                    $filename = 'tathbeet_' . 'supplier_' . $tathbeet->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                else
                    $filename = 'tathbeet_' . 'customer_' . $tathbeet->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';


                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $tathbeet->pdf_path = $filePath;
                $tathbeet->save();

                $tathbeet->update(['pdf_path' => $filePath]);
                break;
            case 'forceDelete':
                $pdf_path = $tathbeet->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }

                break;
        }
    }


    /**
     * إنشاء ملف PDF لطلب معين.
     *
     * تقوم هذه الدالة بإنشاء ملف PDF لطلب معين بناءً على العملية المقدمة.
     * إذا كانت العملية 'store' أو 'update'، فإنها تنشئ ملف PDF جديد للطلب
     * وتحدث الخاصية 'pdf_path'. إذا كانت العملية 'forceDelete'، فإنها
     * تحذف الملف PDF الموجود المرتبط بالطلب.
     *
     * @param int $id معرف كيان الطلب.
     * @param string $operation العملية المراد القيام بها ('store'، 'update'، أو 'forceDelete').
     * @return void
     */
    public function OrderPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/Orders";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }
        $order = Order::with([
            'customer' => function ($query) {
                $query->select('id', 'name', 'code');
            },
            'supplier' => function ($query) {
                $query->select('id', 'name');
            },
            'products'
        ])->withTrashed()->find($id);

        $unitCounts = $order->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;

            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/orders/details/' . $order->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $PdfForms = PdfForm::where('type', 'Order')->get()->sortBy('index')->toArray();


        // جلب الفورمات التي تطابق الشروط
        $titleCode_data = PdfForm::where('type', 'Order')
            ->where(function ($query) {
                $query->where('name', 'title')
                    ->orWhere('name', 'code');
            })
            ->get();

        // التحقق إذا كانت هناك عناصر لـ title و code بنفس ال index
        $titleIndex = $titleCode_data->where('name', 'title')->pluck('index');
        $codeIndex = $titleCode_data->where('name', 'code')->pluck('index');

        $titleValue = $titleCode_data->where('name', 'title')->pluck('value');
        $codeValue = $titleCode_data->where('name', 'code')->pluck('value');

        $sumCodeTitle = null;
        if ($titleIndex->first() == $codeIndex->first()) {  // مقارنة أول قيمة لكل index
            // التحقق من وجود القيم قبل محاولة الوصول إليها
            if ($titleValue->isNotEmpty() && $codeValue->isNotEmpty()) {
                $sumCodeTitle = $titleValue[0] . ' ' . $codeValue[0] . ' : ' . $order->code;

                // بناء مصفوفة جديدة مع إزالة العناصر 'title' و 'code'
                $filteredPdfForms = [];

                foreach ($PdfForms as $pdfFor) {
                    if ($pdfFor['name'] == 'title' || $pdfFor['name'] == 'code') {
                        $pdfFor['index'] = null;
                    }

                    $filteredPdfForms[] = $pdfFor;
                }

                // إضافة العنصر الجديد إلى المصفوفة
                $filteredPdfForms[] = [
                    'value' => $sumCodeTitle,
                    'index' => $titleIndex[0],  // استخدام أول قيمة من الـ index
                    'name' => 'sumCodeTitle'
                ];

                // تحديث المصفوفة الأصلية بعد تعديلها
                $PdfForms = $filteredPdfForms;
            }
        }

        // التحقق إذا كانت هناك أي تقاطع بين ال index لـ title و code

        $company = CompanySetting::first();

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $order->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'order' => $order,
                    'qrCode' => $qrCode,
                    'unitCounts' => $unitCounts,
                    'selectedFields' => $PdfForms,
                    'company' => $company,
                ];


                $html = view('pdf.order', $data)->render();
                $mpdf = $this->pdf($html, 'order', 'D');
                $filename = 'order_' . uniqid() . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                if ($order->customer_id)
                    $filename = 'order_' . 'customer_' . $order->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'order_' . 'supplier_' . $order->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $order->pdf_path = $filePath;
                $order->save();

                break;
            case 'forceDelete':
                $pdf_path = $order->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }

                break;
        }
    }

    /**
     * إنشاء وتحديث أو حذف ملف PDF لسند الدفع.
     *
     * تقوم هذه الدالة بإنشاء ملف PDF يحتوي على معلومات سند الدفع ورمز QR مرتبط. 
     * بناءً على العملية المحددة (store، update، forceDelete)، تقوم الدالة إما بإنشاء ملف PDF جديد وتحديث مساره، 
     * أو حذف ملف PDF موجود.
     *
     * @param int $id معرف سند الدفع الذي سيتم إنشاء PDF له.
     * @param string $operation نوع العملية التي تحدد التصرف المطلوب ('store'، 'update'، 'forceDelete').
     *
     * @return void
     */
    public function payBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/PayBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $payBond = PayBond::withTrashed()->find($id);

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/pays/details/' . $payBond->id);
        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'payBond' => $payBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $payBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'payBond' => $payBond,
                    'qrCode' => $qrCode,
                ];


                $html = view('pdf.payBond', $data)->render();
                $mpdf = $this->pdf($html, 'payBond', 'D');
                // $filename = 'pay_bond_' . uniqid() . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                if ($payBond->customer_id)
                    $filename = 'pay_bond_' . 'customer_' . $payBond->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'pay_bond_' . 'supplier_' . $payBond->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");

                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $payBond->pdf_path = $filePath;
                $payBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $payBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function returnSaleInvoicePDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/ReturnSaleInvoices";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $returnSaleInvoice = ReturnSaleInvoice::withTrashed()->find($id);

        $unitCounts = $returnSaleInvoice->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;

            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/returnSales/details/' . $returnSaleInvoice->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $PdfForms = PdfForm::where('type', 'ReturnSale')->get()->sortBy('index')->toArray();

        $titleCode_data = PdfForm::where('type', 'ReturnSale')

            ->where(function ($query) {
                $query->where('name', 'title')
                    ->orWhere('name', 'code');
            })
            ->get();

        // التحقق إذا كانت هناك عناصر لـ title و code بنفس ال index
        $titleIndex = $titleCode_data->where('name', 'title')->pluck('index');
        $codeIndex = $titleCode_data->where('name', 'code')->pluck('index');

        $titleValue = $titleCode_data->where('name', 'title')->pluck('value');
        $codeValue = $titleCode_data->where('name', 'code')->pluck('value');

        $sumCodeTitle = null;
        if ($titleIndex->first() == $codeIndex->first()) {  // مقارنة أول قيمة لكل index
            // التحقق من وجود القيم قبل محاولة الوصول إليها
            if ($titleValue->isNotEmpty() && $codeValue->isNotEmpty()) {
                $sumCodeTitle = $titleValue[0] . ' ' . $codeValue[0] . ' : ' . $returnSaleInvoice->code;

                // بناء مصفوفة جديدة مع إزالة العناصر 'title' و 'code'
                $filteredPdfForms = [];

                foreach ($PdfForms as $pdfFor) {
                    if ($pdfFor['name'] == 'title' || $pdfFor['name'] == 'code') {
                        $pdfFor['index'] = null;
                    }

                    $filteredPdfForms[] = $pdfFor;
                }

                // إضافة العنصر الجديد إلى المصفوفة
                $filteredPdfForms[] = [
                    'value' => $sumCodeTitle,
                    'index' => $titleIndex[0],  // استخدام أول قيمة من الـ index
                    'name' => 'sumCodeTitle'
                ];

                // تحديث المصفوفة الأصلية بعد تعديلها
                $PdfForms = $filteredPdfForms;
            }
        }

        $company = CompanySetting::first();

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $returnSaleInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'returnSaleInvoice' => $returnSaleInvoice,
                    'qrCode' => $qrCode,
                    'unitCounts' => $unitCounts,
                    'selectedFields' => $PdfForms,
                    'company' => $company,
                ];


                $html = view('pdf.returnSaleInvoice', $data)->render();
                $mpdf = $this->pdf($html, 'returnSaleInvoice', 'D');
                if ($returnSaleInvoice->customer_id)
                    $filename = 'return_sale_invoice_' . 'customer_' . $returnSaleInvoice->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $returnSaleInvoice->pdf_path = $filePath;
                $returnSaleInvoice->save();
                break;
            case 'forceDelete':
                $pdf_path = $returnSaleInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function returnPurchaseInvoicePDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/ReturnPurchaseInvoices";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        // dd($directories);
        $returnPurchaseInvoice = ReturnPurchaseInvoice::withTrashed()->find($id);
        $unitCounts = $returnPurchaseInvoice->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;
            // $unitName = Unit::select('name')->find($unitId);

            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/returnPurchases/details/' . $returnPurchaseInvoice->id);
        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);



        $PdfForms = PdfForm::where('type', 'ReturnPurchase')->get()->sortBy('index')->toArray();
        $titleCode_data = PdfForm::where('type', 'ReturnPurchase')

            ->where(function ($query) {
                $query->where('name', 'title')
                    ->orWhere('name', 'code');
            })
            ->get();

        // التحقق إذا كانت هناك عناصر لـ title و code بنفس ال index
        $titleIndex = $titleCode_data->where('name', 'title')->pluck('index');
        $codeIndex = $titleCode_data->where('name', 'code')->pluck('index');

        $titleValue = $titleCode_data->where('name', 'title')->pluck('value');
        $codeValue = $titleCode_data->where('name', 'code')->pluck('value');

        $sumCodeTitle = null;
        if ($titleIndex->first() == $codeIndex->first()) {  // مقارنة أول قيمة لكل index
            // التحقق من وجود القيم قبل محاولة الوصول إليها
            if ($titleValue->isNotEmpty() && $codeValue->isNotEmpty()) {
                $sumCodeTitle = $titleValue[0] . ' ' . $codeValue[0] . ' : ' . $returnPurchaseInvoice->code;

                // بناء مصفوفة جديدة مع إزالة العناصر 'title' و 'code'
                $filteredPdfForms = [];

                foreach ($PdfForms as $pdfFor) {
                    if ($pdfFor['name'] == 'title' || $pdfFor['name'] == 'code') {
                        $pdfFor['index'] = null;
                    }

                    $filteredPdfForms[] = $pdfFor;
                }

                // إضافة العنصر الجديد إلى المصفوفة
                $filteredPdfForms[] = [
                    'value' => $sumCodeTitle,
                    'index' => $titleIndex[0],  // استخدام أول قيمة من الـ index
                    'name' => 'sumCodeTitle'
                ];

                // تحديث المصفوفة الأصلية بعد تعديلها
                $PdfForms = $filteredPdfForms;
            }
        }

        $company = CompanySetting::first();

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $returnPurchaseInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'returnPurchaseInvoice' => $returnPurchaseInvoice,
                    'qrCode' => $qrCode,
                    'unitCounts' => $unitCounts,
                    'selectedFields' => $PdfForms,
                    'company' => $company,
                ];
                $html = view('pdf.returnPurchaseInvoice', $data)->render();
                $mpdf = $this->pdf($html, 'returnPurchaseInvoice', 'D');
                if ($returnPurchaseInvoice->customer_id)
                    $filename = 'return_Purchase_Invoice' . 'customer_' . $returnPurchaseInvoice->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'return_Purchase_Invoice' . 'supplier_' . $returnPurchaseInvoice->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $returnPurchaseInvoice->pdf_path = $filePath;
                $returnPurchaseInvoice->save();
                break;
            case 'forceDelete':
                $pdf_path = $returnPurchaseInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function PurchaseInvoicePDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/PurchaseInvoices";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $purchaseInvoice = PurchaseInvoice::withTrashed()->find($id);

        $unitCounts = $purchaseInvoice->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;
            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });


        $PdfForms = PdfForm::where('type', 'Purchase')->get()->sortBy('index')->toArray();


        // جلب الفورمات التي تطابق الشروط
        $titleCode_data = PdfForm::where('type', 'Purchase')

            ->where(function ($query) {
                $query->where('name', 'title')
                    ->orWhere('name', 'code');
            })
            ->get();

        // التحقق إذا كانت هناك عناصر لـ title و code بنفس ال index
        $titleIndex = $titleCode_data->where('name', 'title')->pluck('index');
        $codeIndex = $titleCode_data->where('name', 'code')->pluck('index');

        $titleValue = $titleCode_data->where('name', 'title')->pluck('value');
        $codeValue = $titleCode_data->where('name', 'code')->pluck('value');

        $sumCodeTitle = null;
        if ($titleIndex->first() == $codeIndex->first()) {  // مقارنة أول قيمة لكل index
            // التحقق من وجود القيم قبل محاولة الوصول إليها
            if ($titleValue->isNotEmpty() && $codeValue->isNotEmpty()) {
                $sumCodeTitle = $titleValue[0] . ' ' . $codeValue[0] . ' : ' . $purchaseInvoice->code;

                // بناء مصفوفة جديدة مع إزالة العناصر 'title' و 'code'
                $filteredPdfForms = [];

                foreach ($PdfForms as $pdfFor) {
                    if ($pdfFor['name'] == 'title' || $pdfFor['name'] == 'code') {
                        $pdfFor['index'] = null;
                    }

                    $filteredPdfForms[] = $pdfFor;
                }

                // إضافة العنصر الجديد إلى المصفوفة
                $filteredPdfForms[] = [
                    'value' => $sumCodeTitle,
                    'index' => $titleIndex[0],  // استخدام أول قيمة من الـ index
                    'name' => 'sumCodeTitle'
                ];

                // تحديث المصفوفة الأصلية بعد تعديلها
                $PdfForms = $filteredPdfForms;
            }
        }
        $company = CompanySetting::first();


        switch ($operation) {
            case 'store':
            case 'update':

                $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/purchases/details/' . $purchaseInvoice->id);
                $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);
                $pdf_path = $purchaseInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'purchaseInvoice' => $purchaseInvoice,
                    'unitCounts' => $unitCounts,
                    'selectedFields' => $PdfForms,
                    'qrCode' => $qrCode,
                    'company' => $company,
                ];


                $html = view('pdf.purchaseInvoice', $data)->render();
                $mpdf = $this->pdf($html, 'purchaseInvoice', 'D');
                if ($purchaseInvoice->supplier_id)
                    $filename = 'purchase_Invoice_' . 'supplier_' . $purchaseInvoice->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                else
                    $filename = 'purchase_Invoice_' . 'customer_' . $purchaseInvoice->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';


                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $purchaseInvoice->pdf_path = $filePath;
                $purchaseInvoice->save();
                break;
            case 'forceDelete':
                $pdf_path = $purchaseInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }

                break;
        }
    }

    public function ProjectOrderPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/ProjectOrders";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $projectOrder = ProjectOrder::withTrashed()->find($id);

        $unitCounts = $projectOrder->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;
            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });


        $PdfForms = PdfForm::where('type', 'projectOrders')->get()->sortBy('index')->toArray();


        // جلب الفورمات التي تطابق الشروط
        $titleCode_data = PdfForm::where('type', 'projectOrders')

            ->where(function ($query) {
                $query->where('name', 'title')
                    ->orWhere('name', 'code');
            })
            ->get();

        // التحقق إذا كانت هناك عناصر لـ title و code بنفس ال index
        $titleIndex = $titleCode_data->where('name', 'title')->pluck('index');
        $codeIndex = $titleCode_data->where('name', 'code')->pluck('index');

        $titleValue = $titleCode_data->where('name', 'title')->pluck('value');
        $codeValue = $titleCode_data->where('name', 'code')->pluck('value');

        $sumCodeTitle = null;
        if ($titleIndex->first() == $codeIndex->first()) {  // مقارنة أول قيمة لكل index
            // التحقق من وجود القيم قبل محاولة الوصول إليها
            if ($titleValue->isNotEmpty() && $codeValue->isNotEmpty()) {
                $sumCodeTitle = $titleValue[0] . ' ' . $codeValue[0] . ' : ' . $projectOrder->code;

                // بناء مصفوفة جديدة مع إزالة العناصر 'title' و 'code'
                $filteredPdfForms = [];

                foreach ($PdfForms as $pdfFor) {
                    if ($pdfFor['name'] == 'title' || $pdfFor['name'] == 'code') {
                        $pdfFor['index'] = null;
                    }

                    $filteredPdfForms[] = $pdfFor;
                }

                // إضافة العنصر الجديد إلى المصفوفة
                $filteredPdfForms[] = [
                    'value' => $sumCodeTitle,
                    'index' => $titleIndex[0],  // استخدام أول قيمة من الـ index
                    'name' => 'sumCodeTitle'
                ];

                // تحديث المصفوفة الأصلية بعد تعديلها
                $PdfForms = $filteredPdfForms;
            }
        }
        $company = CompanySetting::first();


        switch ($operation) {
            case 'store':
            case 'update':

                $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/projectOrder/details/' . $projectOrder->id);
                $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);
                $pdf_path = $projectOrder->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'projectOrder' => $projectOrder,
                    'unitCounts' => $unitCounts,
                    'selectedFields' => $PdfForms,
                    'qrCode' => $qrCode,
                    'company' => $company,
                ];


                $html = view('pdf.projectOrder', $data)->render();
                $mpdf = $this->pdf($html, 'projectOrder', 'D');
                if ($projectOrder->project_id)
                    $filename = 'project_Order_' . 'project_' . $projectOrder->project->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';



                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $projectOrder->pdf_path = $filePath;
                $projectOrder->save();
                break;
            case 'forceDelete':
                $pdf_path = $projectOrder->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);

                    // التحقق من صحة المسار النسبي
                    // dd($relativePath); // نقطة اختبار لمعرفة المسار الناتج

                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }

                break;
        }
    }

    public function storehouseEqualityPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/StorehouseEqualities";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }
        $storehouseEquality = StorehouseEquality::withTrashed()->find($id);

        $unitCounts = $storehouseEquality->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;
            // $unitName = Unit::select('name')->find($unitId);

            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/storehouseEqualities/details/' . $storehouseEquality->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'storehouseEquality' => $storehouseEquality,
            'qrCode' => $qrCode,
            'unitCounts' => $unitCounts,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $storehouseEquality->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'storehouseEquality' => $storehouseEquality,
                    'qrCode' => $qrCode,
                    'unitCounts' => $unitCounts,
                ];


                $html = view('pdf.storehouseEquality', $data)->render();
                $mpdf = $this->pdf($html, 'storehouseEquality', 'D');
                $filename = 'storehouse_quality_' . $storehouseEquality->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $storehouseEquality->pdf_path = $filePath;
                $storehouseEquality->save();
                break;
            case 'forceDelete':
                $pdf_path = $storehouseEquality->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function moneyTransferBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/MoneyTransferBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }
        $moneyTransferBond = MoneyTransferBond::with([
            'externalBox' => function ($query) {
                $query->select('boxes.id', 'boxes.name');
            },
            'incomingBox' => function ($query) {
                $query->select('boxes.id', 'boxes.name');
            },
            'externalCurrency' => function ($query) {
                $query->select('currencies.id', 'currencies.name');
            },
            'incomingCurrency' => function ($query) {
                $query->select('currencies.id', 'currencies.name');
            },
            'createdBy' => function ($query) {
                $query->select('users.id', 'users.first_name', 'users.last_name');
            },
            'exchanges',
        ])->withTrashed()->find($id);

        $exchanges = $moneyTransferBond->exchanges;

        foreach ($exchanges as $exchange) {
            $currency = Currency::select('name')->find($exchange->currency_id);
            $exchange['currency_name'] = $currency->name;
            unset($exchange['id']);
            unset($exchange['bondsable_type']);
            unset($exchange['bondsable_id']);
            unset($exchange['currency_id']);
            unset($exchange['created_at']);
            unset($exchange['updated_at']);
        }

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/money-transfers/details/' . $moneyTransferBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'moneyTransferBond' => $moneyTransferBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $moneyTransferBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'moneyTransferBond' => $moneyTransferBond,
                    'qrCode' => $qrCode,
                ];

                $html = view('pdf.moneyTransferBond', $data)->render();
                $mpdf = $this->pdf($html, 'moneyTransferBond', 'D');
                $filename = 'money_transfer_bond_' . $moneyTransferBond->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $moneyTransferBond->pdf_path = $filePath;
                $moneyTransferBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $moneyTransferBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function bankMoneyTransferBondPDF($id, $operation)
    {

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/BankMoneyTransferBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $bankMoneyTransferBond = BankMoneyTransferBond::with([
            'fromBox' => function ($query) {
                $query->with('currency:id,name')->select('id', 'name', 'currency_id');
            },
            'toBox' => function ($query) {
                $query->with('currency:id,name')->select('id', 'name', 'currency_id');
            },
            'createdBy' => function ($query) {
                $query->select('users.id', 'users.first_name', 'users.last_name');
            },
            'exchanges',
        ])->withTrashed()->find($id);

        $exchanges = $bankMoneyTransferBond->exchanges;

        foreach ($exchanges as $exchange) {
            $currency = Currency::select('name')->find($exchange->currency_id);
            $exchange['currency_name'] = $currency->name;
            unset($exchange['id']);
            unset($exchange['bondsable_type']);
            unset($exchange['bondsable_id']);
            unset($exchange['currency_id']);
            unset($exchange['created_at']);
            unset($exchange['updated_at']);
        }

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/bank-money-transfers/details/' . $bankMoneyTransferBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'bankMoneyTransferBond' => $bankMoneyTransferBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $bankMoneyTransferBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'bankMoneyTransferBond' => $bankMoneyTransferBond,
                    'qrCode' => $qrCode,
                ];

                $html = view('pdf.bankMoneyTransferBond', $data)->render();
                $mpdf = $this->pdf($html, 'bankMoneyTransferBond', 'D');
                $filename = 'bank_money_transfer_bond_' . $bankMoneyTransferBond->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $bankMoneyTransferBond->pdf_path = $filePath;
                $bankMoneyTransferBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $bankMoneyTransferBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function receiptBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/ReceiptBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $receiptBond = receiptBond::withTrashed()->find($id);
        $data = [
            'receiptBond' => $receiptBond,
        ];
        switch ($operation) {
            case 'store':
            case 'update':
                $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/receipts/details/' . $receiptBond->id);
                $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);
                $pdf_path = $receiptBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'receiptBond' => $receiptBond,
                    'qrCode' => $qrCode,
                ];


                $html = view('pdf.receiptBond', $data)->render();
                $mpdf = $this->pdf($html, 'receiptBond', 'D');

                if ($receiptBond->supplier_id)
                    $filename = 'receipt_bond_' . 'supplier_' . $receiptBond->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'receipt_bond_' . 'customer_' . $receiptBond->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';


                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $receiptBond->pdf_path = $filePath;
                $receiptBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $receiptBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function saleInvoicePDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/SaleInvoices";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $saleInvoice = SaleInvoice::withTrashed()->find($id);

        $unitCounts = $saleInvoice->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;
            // $unitName = Unit::select('name')->find($unitId);

            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/invoices/sales/details/' . $saleInvoice->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);


        //

        $PdfForms = PdfForm::where('type', 'Sale')->get()->sortBy('index')->toArray();
        $titleCode_data = PdfForm::where('type', 'Sale')
            ->where(function ($query) {
                $query->where('name', 'title')
                    ->orWhere('name', 'code');
            })
            ->get();

        // التحقق إذا كانت هناك عناصر لـ title و code بنفس ال index
        $titleIndex = $titleCode_data->where('name', 'title')->pluck('index');
        $codeIndex = $titleCode_data->where('name', 'code')->pluck('index');

        $titleValue = $titleCode_data->where('name', 'title')->pluck('value');
        $codeValue = $titleCode_data->where('name', 'code')->pluck('value');

        $sumCodeTitle = null;
        if ($titleIndex->first() == $codeIndex->first()) {  // مقارنة أول قيمة لكل index
            // التحقق من وجود القيم قبل محاولة الوصول إليها
            if ($titleValue->isNotEmpty() && $codeValue->isNotEmpty()) {
                $sumCodeTitle = $titleValue[0] . ' ' . $codeValue[0] . ' : ' . $saleInvoice->code;

                // بناء مصفوفة جديدة مع إزالة العناصر 'title' و 'code'
                $filteredPdfForms = [];
                foreach ($PdfForms as $pdfFor) {
                    if ($pdfFor['name'] == 'title' || $pdfFor['name'] == 'code') {
                        $pdfFor['index'] = null;
                    }

                    $filteredPdfForms[] = $pdfFor;
                }

                // إضافة العنصر الجديد إلى المصفوفة
                $filteredPdfForms[] = [
                    'value' => $sumCodeTitle,
                    'index' => $titleIndex[0],  // استخدام أول قيمة من الـ index
                    'name' => 'sumCodeTitle'
                ];

                // تحديث المصفوفة الأصلية بعد تعديلها
                $PdfForms = $filteredPdfForms;
            }
        }

        $company = CompanySetting::first();

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $saleInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'saleInvoice' => $saleInvoice,
                    'qrCode' => $qrCode,
                    'unitCounts' => $unitCounts,
                    'selectedFields' => $PdfForms,
                    'company' => $company,
                ];


                $html = view('pdf.saleInvoice', $data)->render();
                $mpdf = $this->pdf($html, 'saleInvoice', 'D');
                if ($saleInvoice->customer_id)
                    $filename = 'sale_invoice_' . 'customer_' . $saleInvoice->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'sale_invoice_' . 'supplier_' . $saleInvoice->supplier->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                $filePath = storage_path("app/$subFolder/$filename");

                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $saleInvoice->pdf_path = $filePath;
                $saleInvoice->save();
                break;
            case 'forceDelete':
                $pdf_path = $saleInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function personalExpensesBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/PersonalExpensesBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $personalExpensesBond = PersonalExpensesBond::withTrashed()->find($id);

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/personal-expenses/details/' . $personalExpensesBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'personalExpensesBond' => $personalExpensesBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $personalExpensesBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'personalExpensesBond' => $personalExpensesBond,
                    'qrCode' => $qrCode,
                ];


                $html = view('pdf.personalExpensesBond', $data)->render();
                $mpdf = $this->pdf($html, 'personalExpensesBond', 'D');
                $filename = 'personal_Expenses_Bond' . $personalExpensesBond->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';

                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $personalExpensesBond->pdf_path = $filePath;
                $personalExpensesBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $personalExpensesBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function userMoneyTransferBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/UserMoneyTransferBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }
        $userMoneyTransferBond = UserMoneyTransferBond::withTrashed()->find($id);

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/user-money-transfers/details/' . $userMoneyTransferBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'userMoneyTransferBond' => $userMoneyTransferBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $userMoneyTransferBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'userMoneyTransferBond' => $userMoneyTransferBond,
                    'qrCode' => $qrCode,
                ];


                $html = view('pdf.userMoneyTransferBond', $data)->render();
                $mpdf = $this->pdf($html, 'userMoneyTransferBond', 'D');

                if ($userMoneyTransferBond->user_id)
                    $filename = 'user_money_transfer_bond_' . 'user_' . $userMoneyTransferBond->user->first_name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'user_money_transfer_bond_' . uniqid() . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $userMoneyTransferBond->pdf_path = $filePath;
                $userMoneyTransferBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $userMoneyTransferBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function firstTermInvoicePDF($id, $operation)
    {

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/FirstTermInvoices";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $firstTermInvoice = FirstTermInvoice::withTrashed()->find($id);

        $unitCounts = $firstTermInvoice->products->groupBy('unit_id')->map(function ($products, $unitId) {
            $unitName = Unit::find($unitId)->name;

            return [
                'name' => $unitName,
                'count' => $products->sum('pivot.quantity')
            ];
        });


        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/trade-reports/first-and-last-term/firstTermInvoices/details/' . $firstTermInvoice->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'firstTermInvoice' => $firstTermInvoice,
            'qrCode' => $qrCode,
            'unitCounts' => $unitCounts,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $firstTermInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'firstTermInvoice' => $firstTermInvoice,
                    'qrCode' => $qrCode,
                    'unitCounts' => $unitCounts,
                ];

                $html = view('pdf.firstTermInvoice', $data)->render();
                $mpdf = $this->pdf($html, 'firstTermInvoice', 'D');
                $filename = 'first_term_invoice_' . $firstTermInvoice->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path
                $firstTermInvoice->pdf_path = $filePath;
                $firstTermInvoice->save();
                break;
            case 'forceDelete':
                $pdf_path = $firstTermInvoice->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function partnersExpensesBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/PartnersExpensesBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }
        $partnersExpensesBond = PartnersExpensesBond::with([
            'box',
            'currency',
            'category' => function ($query) {
                $query->select('id', 'code', 'name');
            },
            'partner' => function ($query) {
                $query->select('id', 'code', 'name', 'currency_id')->with(['currency']);
            },
            'exchanges',
        ])->withTrashed()->find($id);

        $exchanges = $partnersExpensesBond->exchanges;

        foreach ($exchanges as $exchange) {
            $currency = Currency::select('name')->find($exchange->currency_id);
            $exchange['currency_name'] = $currency->name;
            unset($exchange['id']);
            unset($exchange['bondsable_type']);
            unset($exchange['bondsable_id']);
            unset($exchange['currency_id']);
            unset($exchange['created_at']);
            unset($exchange['updated_at']);
        }

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/partners-expenses/details/' . $partnersExpensesBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'partnersExpensesBond' => $partnersExpensesBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $partnersExpensesBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'partnersExpensesBond' => $partnersExpensesBond,
                    'qrCode' => $qrCode,
                ];

                $html = view('pdf.partnersExpensesBond', $data)->render();
                $mpdf = $this->pdf($html, 'partnersExpensesBond', 'D');
                $filename = 'partners_expenses_bond_' . $partnersExpensesBond->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $partnersExpensesBond->pdf_path = $filePath;
                $partnersExpensesBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $partnersExpensesBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function partnerPayBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/PartnerPayBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }
        $partnerPayBond = PartnerPayBond::with([
            'box',
            'currency',
            'partner' => function ($query) {
                $query->select('id', 'code', 'name', 'currency_id')->with(['currency']);
            },
            'exchanges',
        ])->withTrashed()->find($id);

        $exchanges = $partnerPayBond->exchanges;

        foreach ($exchanges as $exchange) {
            $currency = Currency::select('name')->find($exchange->currency_id);
            $exchange['currency_name'] = $currency->name;
            unset($exchange['id']);
            unset($exchange['bondsable_type']);
            unset($exchange['bondsable_id']);
            unset($exchange['currency_id']);
            unset($exchange['created_at']);
            unset($exchange['updated_at']);
        }

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/partners-pays/details/' . $partnerPayBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'partnerPayBond' => $partnerPayBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $partnerPayBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'partnerPayBond' => $partnerPayBond,
                    'qrCode' => $qrCode,
                ];

                $html = view('pdf.partnerPayBond', $data)->render();
                $mpdf = $this->pdf($html, 'partnerPayBond', 'D');
                $filename = 'partner_pay_bond_' . $partnerPayBond->id . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $partnerPayBond->pdf_path = $filePath;
                $partnerPayBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $partnerPayBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    public function differenceBondPDF($id, $operation)
    {
        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }

        $subFolder = "$latestFolder/DifferenceBonds";
        if (!Storage::disk('local')->exists($subFolder)) {
            Storage::disk('local')->makeDirectory($subFolder);
        }

        $directories = Storage::disk('local')->directories('tables-logs');
        $latestFolder = collect($directories)->sortDesc()->first(); // أحدث مجلد

        if (!$latestFolder) {
            // إذا لم يوجد أي مجلد، يتم إنشاء مجلد جديد
            $folderName = now()->format('Y-m-d_H-i-s');
            $latestFolder = "tables-logs/$folderName";
            Storage::disk('local')->makeDirectory($latestFolder);
        }
        $differenceBond = DifferenceBond::with([
            'currency',
            'customer' => function ($query) {
                $query->select('id', 'code', 'name', 'currency_id')->with(['currency']);
            },
            'exchanges',
        ])->withTrashed()->find($id);

        $exchanges = $differenceBond->exchanges;

        foreach ($exchanges as $exchange) {
            $currency = Currency::select('name')->find($exchange->currency_id);
            $exchange['currency_name'] = $currency->name;
            unset($exchange['id']);
            unset($exchange['bondsable_type']);
            unset($exchange['bondsable_id']);
            unset($exchange['currency_id']);
            unset($exchange['created_at']);
            unset($exchange['updated_at']);
        }

        $qrCode = QrCode::size(75)->generate(env("WEB_URL") . '/bonds/differences/details/' . $differenceBond->id);

        $qrCode = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $qrCode);

        $data = [
            'differenceBond' => $differenceBond,
            'qrCode' => $qrCode,
        ];

        switch ($operation) {
            case 'store':
            case 'update':
                $pdf_path = $differenceBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                $data = [
                    'differenceBond' => $differenceBond,
                    'qrCode' => $qrCode,
                ];

                $html = view('pdf.differenceBond', $data)->render();
                $mpdf = $this->pdf($html, 'differenceBond', 'D');
                if ($differenceBond->customer_id)
                    $filename = 'difference_Bond' . 'customer_' . $differenceBond->customer->name . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                else
                    $filename = 'difference_bond_' . uniqid() . '_' . now()->format('Y-m-d_H-i-s') . '.pdf';
                $filePath = storage_path("app/$subFolder/$filename");
                $mpdf->Output($filePath, 'F'); // Save the file to the specified path

                $differenceBond->pdf_path = $filePath;
                $differenceBond->save();
                break;
            case 'forceDelete':
                $pdf_path = $differenceBond->pdf_path ?? null;
                if ($pdf_path) {
                    // تطبيع المسار (تغيير \ إلى /)
                    $normalizedPath = str_replace('\\', '/', $pdf_path);

                    // استخراج المسار النسبي بناءً على مسار التخزين المحلي
                    $relativePath = str_replace(str_replace('\\', '/', storage_path('app')) . '/', '', $normalizedPath);
                    // التحقق من وجود الملف
                    if (Storage::disk('local')->exists($relativePath)) {
                        // حذف الملف
                        Storage::disk('local')->delete($relativePath);
                    }
                }
                break;
        }
    }

    /**
     * الحصول على سعر المنتج للعميل بناءً على عملة العميل وعملة المنتج.
     *
     * تقوم هذه الدالة بحساب سعر المنتج بناءً على نوع السعر المحدد للعميل، وتقوم بتحويل السعر 
     * إلى عملة العميل إذا كانت مختلفة عن عملة المنتج.
     *
     * @param int $productId معرف المنتج.
     * @param int $customerId معرف العميل.
     *
     * @return float السعر المحسوب للمنتج للعميل.
     */
    public function getProductPriceForCustomer($productId, $customerId)
    {
        $product = Product::find($productId);
        $customer = Customer::find($customerId);


        // if ($customer->price_type_id == 1) {
        //     $price = $product->price->wholesale_price;
        // } elseif ($customer->price_type_id == 2) {
        //     $price = $product->price->separate_price;
        // } elseif ($customer->price_type_id == 3) {
        //     $price = $product->price->consumer_price;
        // } elseif ($customer->price_type_id == 4) {
        //     $price = $product->price->state_price;
        // } elseif ($customer->price_type_id == 5) {
        //     $price = $product->price->cost_price;
        // } elseif ($customer->price_type_id == 6) {
        //     $price = $product->price->purchasing_price;
        // } elseif ($customer->price_type_id == 7) {
        //     $price = $product->price->list_price;
        // }

        $price = Price::where('product_id', $productId)->where('price_type_id', $customer->price_type_id)->first()->value('value');

        $productCurrency = $product->currency_id;
        $customerCurrency = $customer->currency_id;

        // الحصول على معرف العملة الافتراضية
        $defaultCurrency = Currency::where('is_default', 1)->value('id');

        // التحقق مما إذا كانت عملة المنتج تختلف عن عملة العميل
        if ($productCurrency != $customerCurrency) {

            // إذا كانت عملة العميل هي العملة الافتراضية
            if ($customerCurrency == $defaultCurrency) {

                // الحصول على سعر الصرف لعملة المنتج وتحويل السعر
                $exchangeRate = Exchange::where('currency_id', $productCurrency)->latest()->value('value');
                $price *= $exchangeRate;
            }
            // إذا كانت عملة المنتج هي العملة الافتراضية
            elseif ($productCurrency == $defaultCurrency) {
                // الحصول على سعر الصرف لعملة العميل وتحويل السعر
                $exchangeRate = Exchange::where('currency_id', $customerCurrency)->latest()->value('value');
                $price /= $exchangeRate;
            }
            // إذا كانت كل من عملة المنتج وعملة العميل مختلفة عن الافتراضية
            else {
                // الحصول على أسعار الصرف لكل من عملة المنتج وعملة العميل
                $productExchangeRate = Exchange::where('currency_id', $productCurrency)->latest()->value('value');
                $customerExchangeRate = Exchange::where('currency_id', $customerCurrency)->latest()->value('value');

                // تحويل السعر باستخدام كل من أسعار الصرف
                $price = ($price * $productExchangeRate) / $customerExchangeRate;
            }
        }

        return $price;
    }
}
