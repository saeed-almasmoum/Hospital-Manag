<?php

namespace App\Http\Controllers;

use App\Constants\MessageConstants;
use App\Models\Article;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ArticleController extends Controller
{
    use ApiResponseTrait;
    // عرض جميع المقالات
    public function index()
    {
        $articles = Article::with('doctor')->get();
        return $this->apiResponse($articles, MessageConstants::INDEX_SUCCESS, 200);
    }

    // حفظ مقال جديد
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'doctor_id' => 'required|exists:doctors,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $article = Article::create($request->only('title', 'content', 'doctor_id'));
        return $this->apiResponse($article, MessageConstants::STORE_SUCCESS, 201);
    }

    // عرض مقال محدد
    public function show($id)
    {
        $article = Article::with('doctor')->findOrFail($id);
        return $this->apiResponse($article, MessageConstants::SHOW_SUCCESS, 200);
    }

    // تحديث مقال
    public function update(Request $request, $id)
    {
        $article = Article::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'doctor_id' => 'sometimes|required|exists:doctors,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $article->update($request->only('title', 'content', 'doctor_id'));
        return $this->apiResponse($article, MessageConstants::UPDATE_SUCCESS, 201);
    }

    // حذف مقال
    public function destroy($id)
    {
        $Article = Article::find($id);

        if (!$Article) {
            return $this->apiResponse(null, MessageConstants::NOT_FOUND, 404);
        }
        $Article->delete();

        return $this->apiResponse(null, MessageConstants::DELETE_SUCCESS, 200);
    }
}
