<?php


namespace App\Services;

use App\Enums\HttpStatusCode;
use App\Http\Resources\CountryCityResource;
use App\Http\Resources\RecentJoinCandidateResource;
use App\Http\Resources\SearchResource;
use App\Models\Occupation;
use App\Models\Religion;
use App\Models\StudyLevel;
use App\Models\User;
use App\Models\CandidateImage;
use App\Models\CandidateInformation;
use App\Repositories\CandidateImageRepository;
use App\Repositories\CountryRepository;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use App\Traits\CrudTrait;
use Illuminate\Http\Request;
use App\Repositories\CandidateRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use \Illuminate\Support\Facades\DB;
use App\Transformers\CandidateTransformer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response as FResponse;
use App\Repositories\RepresentativeInformationRepository as RepresentativeRepository;


class CandidateService extends ApiBaseService
{

    use CrudTrait;

    const INFORMATION_FETCHED_SUCCESSFULLY = 'Information fetched Successfully!';
    const INFORMATION_UPDATED_SUCCESSFULLY = 'Information updated Successfully!';
    const IMAGE_DELETED_SUCCESSFULLY = 'Image Deleted successfully!';

    /**
     * @var CandidateRepository
     */
    protected $candidateRepository;

    /**
     * @var CandidateRepository
     */
    protected $imageRepository;

    /**
     * @var CandidateTransformer
     */
    protected $candidateTransformer;

    /**
     * CandidateService constructor.
     *
     * @param CandidateRepository $candidateRepository
     */

    /**
     * @var BlockListService
     */
    protected $blockListService;


    /**
     * @var RepresentativeRepository
     */
    protected $representativeRepository;
    /**
     * @var CountryRepository
     */
    private $countryRepository;

    public function __construct(
        CandidateRepository $candidateRepository,
        CandidateImageRepository $imageRepository,
        CandidateTransformer $candidateTransformer,
        BlockListService $blockListService,
        RepresentativeRepository $representativeRepository,
        CountryRepository $countryRepository
    )
    {
        $this->candidateRepository = $candidateRepository;
        $this->imageRepository = $imageRepository;
        $this->candidateTransformer = $candidateTransformer;
        $this->blockListService = $blockListService;
        $this->representativeRepository = $representativeRepository;
        $this->setActionRepository($candidateRepository);
        $this->countryRepository = $countryRepository;
    }

    /**
     * @param $request
     * @return JsonResponse
     */
    public function store($request)
    {
        try {
            $userId = self::getUserId();
            $checkCandidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if ($checkCandidate) {
                return $this->sendSuccessResponse($checkCandidate, 'Candidate Information Already Exists', [], HttpStatusCode::SUCCESS);
            }
            $request['user_id'] = $userId;
            $candidate = $this->candidateRepository->save($request);
            if ($candidate) {
                $userInfo = User::find($userId);
                if ($userInfo) {
                    $userInfo->full_name = trim($request['first_name']) . ' ' . trim($request['last_name']);
                    $userInfo->save();
                }
                return $this->sendSuccessResponse($candidate, 'Information save Successfully!', [], HttpStatusCode::CREATED);
            } else {
                return $this->sendErrorResponse('Something went wrong. try again later', [], FResponse::HTTP_BAD_REQUEST);
            }
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }

    }

    /**
     * fetch candidate all info
     * @param int $userId
     * @return JsonResponse
     */

    public function fetchCandidateInfo(int $userId): JsonResponse
    {
        $candidate = $this->candidateRepository->findOneByProperties([
            'user_id' => $userId
        ]);
        if (!$candidate) {
            throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
        }

        $candidate_info = $this->candidateTransformer->transform($candidate);
        return $this->sendSuccessResponse($candidate_info, self::INFORMATION_FETCHED_SUCCESSFULLY);
    }

    /**
     * fetch resource
     * @return JsonResponse
     */
    public function fetchCandidatePersonalInfo(): JsonResponse
    {
        $userId = self::getUserId();
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $personal_info = $this->candidateTransformer->transformPersonal($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_FETCHED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function candidateBasicInfoStore(Request $request, int $userId): JsonResponse
    {
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->all(CandidateInformation::BASIC_INFO);

            // As BaseRepository update method has bug that's why we have to fallback to model default methods.
            $input = $candidate->fill($input)->toArray();
            $candidate->save($input);
            $personal_info = $this->candidateTransformer->transformPersonalBasic($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * fetch resource
     * @return JsonResponse
     */
    public function fetchProfileInitialInfo(): JsonResponse
    {
        $userId = self::getUserId();
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $data['user'] = $this->candidateTransformer->transform($candidate);
            $country = $this->countryRepository->findAll()->where('status','=',1);
            $data['countries'] = CountryCityResource::collection($country);
            $data['studylevels'] = StudyLevel::orderBy('name')->get();
            $data['religions'] = Religion::where('status', 1)->orderBy('name')->get();
            $data['occupations'] = Occupation::pluck('name', 'id');

            return $this->sendSuccessResponse($data, self::INFORMATION_FETCHED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function candidatePersonalInfoUpdate(Request $request, int $userId): JsonResponse
    {
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->all(CandidateInformation::PERSONAL_INFO);

            // As BaseRepository update method has bug that's why we have to fallback to model default methods.
            $input = $candidate->fill($input)->toArray();
            $candidate->save($input);
            $personal_info = $this->candidateTransformer->transformPersonal($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @return JsonResponse
     */
    public function candidateEssentialPersonalInfoUpdate(Request $request): JsonResponse
    {
        $userId = self::getUserId();
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            dd(User::find(1));
            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->all(CandidateInformation::PERSONAL_ESSENTIAL_INFO);

            // As BaseRepository update method has bug that's why we have to fallback to model default methods.
//            $input = $candidate->fill($input)->toArray();
            $this->candidateRepository->update($input);
//            $candidate->save($input);
            $personal_info = $this->candidateTransformer->transformPersonalEssential($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function candidatePersonalGeneralInfoUpdate(Request $request): JsonResponse
    {
        $userId = self::getUserId();
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->all(CandidateInformation::PERSONAL_GENERAL_INFO);

            // As BaseRepository update method has bug that's why we have to fallback to model default methods.
            $input = $candidate->fill($input)->toArray();
            $candidate->save($input);
            $personal_info = $this->candidateTransformer->transformPersonalGeneral($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @return JsonResponse
     */
    public function candidatePersonalContactInfoUpdate(Request $request): JsonResponse
    {
        $userId = self::getUserId();
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->all(CandidateInformation::PERSONAL_CONTACT_INFO);

            $input = $candidate->fill($input)->toArray();
            $candidate->save($input);
            $personal_info = $this->candidateTransformer->transformPersonalContact($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @return JsonResponse
     */
    public function candidatePersonalMoreAboutInfoUpdate(Request $request): JsonResponse
    {
        $userId = self::getUserId();
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->all(CandidateInformation::PERSONAL_MOREABOUT_INFO);

            $input = $candidate->fill($input)->toArray();
            $candidate->save($input);
            $personal_info = $this->candidateTransformer->transformPersonalMoreAbout($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * fetch resource
     * @param int $userId
     * @return JsonResponse
     */
    public function fetchPreferenceInfo(int $userId): JsonResponse
    {
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $personal_info = $this->candidateTransformer->transformPreference($candidate);
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_FETCHED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * Update resource
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function storePreferenceInfo(Request $request): JsonResponse
    {
        try {
            $userId = self::getUserId();
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);
            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }
            $input = $request->only(CandidateInformation::PREFERENCE_INFO);
            $input = $candidate->fill($input)->toArray();
            DB::beginTransaction();
            $candidate->save($input);

            if ($request->has('pre_has_country_allow_preference')) {
                if ($request->pre_has_country_allow_preference) {
                    $candidate->preferred_countries()->sync($request->pre_countries);
                    $candidate->preferred_cities()->sync($request->pre_cities);
                } else {
                    $candidate->preferred_countries()->detach();
                    $candidate->preferred_cities()->detach();
                }
            }

            if ($request->has('pre_has_country_disallow_preference')) {
                if ($request->pre_has_country_disallow_preference) {
                    $candidate->bloked_countries()->sync(array_fill_keys($request->pre_disallow_countries, ['allow' => 0]));
                    $candidate->blocked_cities()->sync(array_fill_keys($request->pre_disallow_cities, ['allow' => 0]));
                } else {
                    $candidate->bloked_countries()->detach();
                    $candidate->blocked_cities()->detach();
                }
            }

            if ($request->has('pre_nationality')) {
                $candidate->preferred_nationality()->sync($request->pre_nationality);
            }
            $personal_info = $this->candidateTransformer->transformPreference($candidate);
            DB::commit();
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function storePreferenceAbout(Request $request): JsonResponse
    {
        try {
            $userId = self::getUserId();
            $candidate = $this->candidateRepository->findOneByProperties(['user_id' => $userId]);
            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }

            $candidate->pre_partner_age_min = $request->input('pre_partner_age_min') ?? 18;
            $candidate->pre_partner_age_max = $request->input('pre_partner_age_max') ?? 68;
            $candidate->pre_height_min = $request->input('pre_height_min') ?? 5.00;
            $candidate->pre_height_max = $request->input('pre_height_max') ?? 7.00;
            $candidate->pre_has_country_allow_preference = $request->input('pre_has_country_allow_preference') ?? 0;
            $candidate->pre_has_country_disallow_preference = $request->input('pre_has_country_disallow_preference') ?? 0;
            $candidate->pre_partner_religions = $request->input('pre_partner_religions') ?? 0;
            $candidate->pre_ethnicities = $request->input('pre_ethnicities') ?? 0;
            $candidate->pre_study_level_id = $request->input('pre_study_level_id') ?? 0;
            $candidate->pre_employment_status = $request->input('pre_employment_status') ?? 0;
            $candidate->pre_occupation = $request->input('pre_occupation') ?? 0;
            $candidate->pre_preferred_divorcee = $request->input('pre_preferred_divorcee') ?? 0;
            $candidate->pre_preferred_divorcee_child = $request->input('pre_preferred_divorcee_child') ?? 0;
            $candidate->pre_other_preference = $request->input('pre_other_preference') ?? null;
            $candidate->pre_description = $request->input('pre_description') ?? null;

            DB::beginTransaction();
            $candidate->save();

            if ($request->has('pre_has_country_allow_preference') && count($request->pre_partner_comes_from) > 0) {
                $country = [];
                $city = [];
                foreach ($request->pre_partner_comes_from as $key => $county) {
                    $country[] = ['candidate_pre_country_id' => $county['country'], 'candidate_pre_city_id' => $county['city']];
                    $city[] = ['city_id' => $county['city'], 'country_id' => $county['country']];
                }
                if ($request->pre_has_country_allow_preference) {
                    if (count($country) > 0):
                        $candidate->preferred_countries()->detach();
                        $candidate->preferred_countries()->sync($country);
                    endif;
                    if (count($city) > 0):
                        $candidate->preferred_cities()->detach();
                        $candidate->preferred_cities()->sync($city);
                    endif;
                } else {
                    $candidate->preferred_countries()->detach();
                    $candidate->preferred_cities()->detach();
                }
            }

            if ($request->has('pre_has_country_disallow_preference') && count($request->pre_disallow_preference) > 0) {
                $bcountry = [];
                $bcity = [];
                foreach ($request->pre_disallow_preference as $key => $bcounty) {

                    $bcountry[] = ['candidate_pre_country_id' => $bcounty['country'], 'candidate_pre_city_id' => $bcounty['city'], 'allow' => '0'];
                    $bcity[] = ['city_id' => $bcounty['city'], 'country_id' => $bcounty['country'], 'allow' => 0];

                }
                if ($request->pre_has_country_disallow_preference) {
                    if (count($bcountry) > 0):
                        $candidate->bloked_countries()->detach();
                        $candidate->bloked_countries()->sync($bcountry);
                    endif;
                    if (count($bcity) > 0):
                        $candidate->blocked_cities()->detach();
                        $candidate->blocked_cities()->sync($bcity);
                    endif;
                } else {
                    $candidate->bloked_countries()->detach();
                    $candidate->blocked_cities()->detach();
                }
            }

            if ($request->has('pre_nationality')) {
                $candidate->preferred_nationality()->sync($request->pre_nationality);
            }
            $personal_info = $this->candidateTransformer->transformPreference($candidate);
            DB::commit();
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendErrorResponse($exception->getMessage());
        }

    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function storePreferenceRate(Request $request): JsonResponse
    {
        try {
            $userId = self::getUserId();
            $candidate = $this->candidateRepository->findOneByProperties(['user_id' => $userId]);
            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }

            $candidate->pre_strength_of_character_rate = $request->input('pre_strength_of_character_rate') ?? 0;
            $candidate->pre_look_and_appearance_rate = $request->input('pre_look_and_appearance_rate') ?? 0;
            $candidate->pre_religiosity_or_faith_rate = $request->input('pre_religiosity_or_faith_rate') ?? 0;
            $candidate->pre_manners_socialskill_ethics_rate = $request->input('pre_manners_socialskill_ethics_rate') ?? 0;
            $candidate->pre_emotional_maturity_rate = $request->input('pre_emotional_maturity_rate') ?? 0;
            $candidate->pre_good_listener_rate = $request->input('pre_good_listener_rate') ?? 0;
            $candidate->pre_good_talker_rate = $request->input('pre_good_talker_rate') ?? 0;
            $candidate->pre_wiling_to_learn_rate = $request->input('pre_wiling_to_learn_rate') ?? 0;
            $candidate->pre_family_social_status_rate = $request->input('pre_family_social_status_rate') ?? 0;
            $candidate->pre_employment_wealth_rate = $request->input('pre_employment_wealth_rate') ?? 0;
            $candidate->pre_education_rate = $request->input('pre_education_rate') ?? 0;

            DB::beginTransaction();
            $candidate->save();

            $personal_info = $this->candidateTransformer->transformPreference($candidate);
            DB::commit();
            return $this->sendSuccessResponse($personal_info, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendErrorResponse($exception->getMessage());
        }

    }

    /**
     * this function use for getting candidate family informations
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function candidateFamilyInfoList($request)
    {
        try {
            $uid = $request->get('uid');
            $candidateinfo = $this->candidateRepository->findOneByProperties([
                'user_id' => $uid
            ]);
            if (!empty($candidateinfo)) {
                $responseInfo = array();
                $responseInfo["user_id"] = $candidateinfo->user_id;
                $responseInfo["fi_father_name"] = $candidateinfo->fi_father_name;
                $responseInfo["fi_father_profession"] = $candidateinfo->fi_father_profession;
                $responseInfo["fi_mother_name"] = $candidateinfo->fi_mother_name;
                $responseInfo["fi_mother_profession"] = $candidateinfo->fi_mother_profession;
                $responseInfo["fi_siblings_desc"] = $candidateinfo->fi_siblings_desc;
                $responseInfo["fi_country_of_origin"] = $candidateinfo->fi_country_of_origin;
                $responseInfo["fi_family_info"] = $candidateinfo->fi_family_info;
                return $this->sendSuccessResponse($responseInfo, 'Family Info listed successfully');
            } else {
                return $this->sendErrorResponse('Invalid User ID', ['detail' => 'User ID Not found'],
                    HttpStatusCode::BAD_REQUEST
                );
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'FAIL',
                'status_code' => HttpStatusCode::NOT_FOUND,
                'message' => $e->getMessage(),
                'error' => ['details' => $e->getMessage()]
            ], HttpStatusCode::NOT_FOUND);
        }
    }

    /**
     * this function use for updating candidate family informations
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function candidateFamilyInfoUpdate($request)
    {
        try {
            $uid = $request->get('uid');
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $uid
            ]);
            if (!empty($candidate)) {
                // Update family info
                $candidate->fi_father_name = $request->get('father_name');
                $candidate->fi_father_profession = $request->get('father_profession');
                $candidate->fi_mother_name = $request->get('mother_name');
                $candidate->fi_mother_profession = $request->get('mother_profession');
                $candidate->fi_siblings_desc = $request->get('siblings_desc');
                $candidate->fi_country_of_origin = $request->get('country_of_origin');
                $candidate->fi_family_info = $request->get('family_info');
                $candidate->timestamps = false;
                $candidate->save();

                return $this->sendSuccessResponse($candidate, 'Family Info updated successfully');
            } else {
                return $this->sendErrorResponse('Invalid User ID', ['detail' => 'User ID Not found'],
                    HttpStatusCode::BAD_REQUEST
                );
            }


        } catch (Exception $e) {
            return response()->json([
                'status' => 'FAIL',
                'status_code' => HttpStatusCode::NOT_FOUND,
                'message' => $e->getMessage(),
                'error' => ['details' => $e->getMessage()]
            ], HttpStatusCode::NOT_FOUND);
        }
    }

    /**
     * @return JsonResponse
     */
    public function listImage(array $searchCriteria): JsonResponse
    {
        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $searchCriteria['user_id']
            ]);

            $avatar_image_url = url('storage/' . $candidate->per_avatar_url);
            $main_image_url = url('storage/' . $candidate->per_main_image_url);
            $images = $this->imageRepository->findBy($searchCriteria);
            for ($i = 0; $i < count($images); $i++) {
                $images[$i]->image_path = url('storage/' . $images[$i]->image_path);
            }

            $data = array();
            $data["avatar_image_url"] = $avatar_image_url;
            $data["main_image_url"] = $main_image_url;
            $data["other_images"] = $images;


            return $this->sendSuccessResponse($data, self::INFORMATION_FETCHED_SUCCESSFULLY);
        } catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * @param array $input
     * @return JsonResponse
     */
    public function uploadImage(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $userId = self::getUserId();
            $checkRepresentative = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$checkRepresentative) {
                return $this->sendErrorResponse('Candidate information is Not fund', [], HttpStatusCode::NOT_FOUND);
            }
            // update data input status
            $checkRepresentative->data_input_status = 1;

            if ($request->hasFile('per_avatar_url')) {
                $per_avatar_url = $this->singleImageUploadFile($request->file('per_avatar_url'));
                $checkRepresentative->per_avatar_url = $per_avatar_url['image_path'];
            }
            if ($request->hasFile('per_main_image_url')) {
                $per_main_image_url = $this->singleImageUploadFile($request->file('per_main_image_url'));
                $checkRepresentative->per_main_image_url = $per_main_image_url['image_path'];
            }
            if (!empty($request['anybody_can_see'])) {
                $checkRepresentative->anybody_can_see = $request['anybody_can_see'];
            }
            if (!empty($request['only_team_can_see'])) {
                $checkRepresentative->only_team_can_see = $request['only_team_can_see'];
            }
            if (!empty($request['team_connection_can_see'])) {
                $checkRepresentative->team_connection_can_see = $request['team_connection_can_see'];
            }
            $checkRepresentative->save();
            if (isset($request['image']) && count($request['image']) > 0) {
                foreach ($request['image'] as $key => $file) {
                    $requestFile = $request->file("image.$key.image");
                    $requestFileType = $file['type'];
                    $input = $this->singleImageUploadFile($requestFile, $requestFileType);
                    $request['user_id'] = $userId;
                    $request['image_type'] = $requestFileType;
                    $input = array_merge($request->all(), $input);
                    $upload = $this->imageRepository->save($input);
                }
            }
            DB::commit();
            $checkRepresentative->per_avatar_url = (!empty($checkRepresentative->per_avatar_url) ? HttpStatusCode::IMAGE_UPLOAD_LOCATION . $checkRepresentative->per_avatar_url : '');
            return $this->sendSuccessResponse($checkRepresentative, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * @param array $input
     * @param CandidateImage $candidateImage
     * @return JsonResponse
     */
    public function updateImage(Request $request): JsonResponse
    {

        try {
            DB::beginTransaction();
            $userId = self::getUserId();

            $checkRepresentative = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$checkRepresentative) {
                return $this->sendErrorResponse('Candidate information is Not fund', [], HttpStatusCode::NOT_FOUND);
            }
            if ($request->hasFile('per_avatar_url')) {
                $per_avatar_url = $this->singleImageUploadFile($request->file('per_avatar_url'));
                $checkRepresentative->per_avatar_url = $per_avatar_url['image_path'];
            }
            if ($request->hasFile('per_main_image_url')) {
                $per_main_image_url = $this->singleImageUploadFile($request->file('per_main_image_url'));
                $checkRepresentative->per_main_image_url = $per_main_image_url['image_path'];
            }
            if (!empty($request['anybody_can_see'])) {
                $checkRepresentative->anybody_can_see = $request['anybody_can_see'];
            }
            if (!empty($request['only_team_can_see'])) {
                $checkRepresentative->only_team_can_see = $request['only_team_can_see'];
            }
            if (!empty($request['team_connection_can_see'])) {
                $checkRepresentative->team_connection_can_see = $request['team_connection_can_see'];
            }
            $checkRepresentative->save();

            if (isset($request['image']) && count($request['image']) > 0) {
                foreach ($request['image'] as $key => $file) {
                    $imageInfo = $this->imageRepository->findOneByProperties([
                        'user_id' => $userId,
                        'id' => $file['id']
                    ]);

                    $requestFile = $request->file("image.$key.image");
                    $requestFileType = $file['type'];
                    $input = $this->singleImageUploadFile($requestFile, $requestFileType);
                    $imageInfo->image_type = $requestFileType;
                    $imageInfo->image_path = $input['image_path'];
                    $imageInfo->disk = $input['disk'];
                    $imageInfo->image_visibility = $file['visibility'];
//                $this->deleteFile($imageInfo['image_path']);

                    $imageInfo->save();
                }
            }
            DB::commit();
            return $this->sendSuccessResponse($checkRepresentative, self::INFORMATION_UPDATED_SUCCESSFULLY);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * @param CandidateImage $candidateImage
     * @return JsonResponse
     */
    public function deleteImage(CandidateImage $candidateImage): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->deleteFile($candidateImage);
            $candidateImage->delete();
            DB::commit();
            return $this->sendSuccessResponse([], self::IMAGE_DELETED_SUCCESSFULLY);
        } catch (Exception $exception) {
            DB::rollBack();
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * @param CandidateImage $candidateImage
     * @return bool
     * @throws Exception
     */
    private function deleteFile(CandidateImage $candidateImage): bool
    {
        if (!Storage::disk($candidateImage->disk)->exists($candidateImage->image_path)) {
            throw new NotFoundHttpException("Image not found in $candidateImage->disk disk");
        }
        $file_delete_status = Storage::disk($candidateImage->disk)->delete($candidateImage->image_path);
        if (!$file_delete_status) {
            throw new Exception('File can\'t be deleted!');
        }
        return $file_delete_status;
    }

    /**
     * @param Request $request
     * @return array
     */
    private function uploadFile(Request $request): array
    {
        $requestFile = $request->file('image');
        $file = 'user-' . $request->user()->id;
        $image_type = CandidateImage::getImageType($request->{CandidateImage::IMAGE_TYPE});
        $disk = config('filesystems.default', 'local');
        $status = $requestFile->storeAs($file, $image_type . '-' . $requestFile->getClientOriginalName(), $disk);
        return [
            CandidateImage::IMAGE_PATH => $status,
            CandidateImage::IMAGE_DISK => $disk
        ];

    }


    /**
     * @param Request $request
     * @return array
     */
    private function singleImageUploadFile($requestFile, $imageType = null)
    {
        $userId = self::getUserId();
        $image_type = 'gallery';//CandidateImage::getImageType($imageType);
        $file = 'candidate-' . $userId;
        $disk = config('filesystems.default', 'local');
        $status = $requestFile->storeAs($file, $image_type . '-' . $requestFile->getClientOriginalName(), $disk);
        return [
            CandidateImage::IMAGE_PATH => $status,
            CandidateImage::IMAGE_DISK => $disk
        ];

    }

    /**
     * Write code on Construct
     *
     * @return \Illuminate\Http\Response
     */
    public function removeImage($imageName)
    {
        if (Storage::exists(public_path($imageName))) {
            Storage::delete(public_path($imageName));
            return 200;
        } else {
            return 500;
        }
    }

    /**
     * @param Request $request
     * @return array
     */
    public function getCandidateGallery(Request $request)
    {
        $user_id = $request->user_id;
        if (!$user_id) {
            $user_id = Auth::id();
        }

        $candidate = $this->candidateRepository->findOneByProperties([
            "user_id" => $user_id
        ]);

        if (!$candidate) {
            return $this->sendErrorResponse('Candidate not found.', [], HttpStatusCode::NOT_FOUND);
        }

        $searchCriteria = ["user_id" => $user_id];
        $avatar_image_url = url('storage/' . $candidate->per_avatar_url);
        $main_image_url = url('storage/' . $candidate->per_main_image_url);
        $images = $this->imageRepository->findBy($searchCriteria);
        for ($i = 0; $i < count($images); $i++) {
            $images[$i]->image_path = url('storage/' . $images[$i]->image_path);
        }

        $data = array();
        $data["avatar_image_url"] = $avatar_image_url;
        $data["main_image_url"] = $main_image_url;
        $data["other_images"] = $images;

        return $this->sendSuccessResponse($data, self::INFORMATION_FETCHED_SUCCESSFULLY);
    }

    /**
     * @return JsonResponse
     */
    public function reccentJoinCandidate()
    {
        //only candidate can be seen
        //only verified candidate
        //payed candidate(hard logic)
        //register complete (soft hard)
        //if registered they will show here
        //need to  have logic if traffic is low.
        $shortListedCandidates = $this->candidateRepository->findBy([
            'data_input_status' => 1,
            'per_page' => 3,
        ], null, ['column' => 'id', 'direction' => 'desc']);
        $formatted_data = RecentJoinCandidateResource::collection($shortListedCandidates);
        return $this->sendSuccessResponse($formatted_data, 'Recently join candidate List');
    }

    /**
     * @return JsonResponse
     */
    public function suggestions()
    {
        $userId = self::getUserId();
        $userInfo = Self::getUserInfo();
        $parpage = 10;
        $search = $this->actionRepository->getModel()->newQuery();

        // check block listed user
        if (!empty($userId)) {
            $blockUser = array();
            $silgleBLockList = $this->blockListService->blockListByUser($userId);
            if (count($silgleBLockList) >= 1) {
                $blockUser = $silgleBLockList;
            }
            $teamBlockList = $this->blockListService->getTeamBlockListByUser($userId);

            if (!empty($teamBlockList) && count($teamBlockList) >= 1) {
                if (count($silgleBLockList) >= 1) {
                    $combineBlockUser = array_merge($silgleBLockList->toArray(), $teamBlockList->toArray());

                } else {
                    $combineBlockUser = $teamBlockList;
                }
                $search->whereNotIn('user_id', $combineBlockUser);
            }

        }
        // Check user status
        $search->join('users', function ($join) {
            $join->on('users.id', '=', 'candidate_information.user_id')
                ->where('status', '=', 1);
        });

        if ($userInfo['account_type'] == 1):
            $candidateInfo = $this->candidateRepository->findOneBy(['user_id' => $userId]);
            if (!empty($candidateInfo)):

                $minAge = (!empty($candidateInfo['pre_partner_age_min'])) ? Carbon::now()->subYear($candidateInfo['pre_partner_age_min'])->format('Y-m-d') : Carbon::now()->subYear(16)->format('Y-m-d');
                $maxAge = (isset($candidateInfo['pre_partner_age_max']) && !empty($candidateInfo['pre_partner_age_max'])) ? Carbon::now()->subYear($candidateInfo['pre_partner_age_max'])->format('Y-m-d') : Carbon::now()->subYear(40)->format('Y-m-d');
                $minHeight = (isset($candidateInfo['pre_height_min']) && !empty($candidateInfo['pre_height_min']) && $candidateInfo['pre_height_min'] > 3) ? $candidateInfo['pre_height_min'] : 3;
                $maxHeight = (isset($candidateInfo['pre_height_max']) && !empty($candidateInfo['pre_height_max']) && $candidateInfo['pre_height_max'] > 3) ? $candidateInfo['pre_height_max'] : 8;


                $search->whereBetween('dob', [$maxAge, $minAge]);
                $search->whereBetween('per_height', [$minHeight, $maxHeight]);


                // pre_preferred_divorcee
//            if (isset($candidateInfo['pre_preferred_divorcee']) and !empty($candidateInfo['pre_preferred_divorcee'])) {
//                $pre_preferred_divorcee = $candidateInfo['pre_preferred_divorcee'];
//                $search->where('per_mother_tongue', '=', $pre_preferred_divorcee);
//            }

                // Religion
                if (isset($candidateInfo['pre_partner_religions']) and !empty($candidateInfo['pre_partner_religions'])) {
                    $religion = explode(',', $candidateInfo['pre_partner_religions']);
                    $search->whereIn('per_religion_id', $religion);
                }
                //  ethnicity
                if (isset($candidateInfo['pre_ethnicities']) and !empty($candidateInfo['pre_ethnicities'])) {
                    $per_ethnicity = $candidateInfo['pre_ethnicities'];
                    $search->orWhere('per_ethnicity', 'like', '%' . $per_ethnicity . '%');
                }
                // per_marital_status
                if (isset($candidateInfo['marital_status']) and !empty($candidateInfo['marital_status'])) {
                    $per_marital_status = $candidateInfo['marital_status'];
                    $search->where('per_marital_status', '=', $per_marital_status);
                }

                // per_occupation
                if (isset($candidateInfo['pre_occupation']) and !empty($candidateInfo['pre_occupation'])) {
                    $per_occupation = $candidateInfo['pre_occupation'];
                    $search->orWhere('per_occupation', 'like', '%' . $per_occupation . '%');
                }

                // pre_strength_of_character_rate
                if (isset($candidateInfo['pre_strength_of_character_rate']) and !empty($candidateInfo['pre_strength_of_character_rate'])) {
                    $pre_strength_of_character_rate = $candidateInfo['pre_strength_of_character_rate'];
                    $search->orWhere('pre_strength_of_character_rate', 'like', '%' . $pre_strength_of_character_rate . '%');
                }
                // pre_look_and_appearance_rate
                if (isset($candidateInfo['pre_look_and_appearance_rate']) and !empty($candidateInfo['pre_look_and_appearance_rate'])) {
                    $pre_look_and_appearance_rate = $candidateInfo['pre_look_and_appearance_rate'];
                    $search->orWhere('pre_look_and_appearance_rate', 'like', '%' . $pre_look_and_appearance_rate . '%');
                }

                // pre_religiosity_or_faith_rate
                if (isset($candidateInfo['pre_religiosity_or_faith_rate']) and !empty($candidateInfo['pre_religiosity_or_faith_rate'])) {
                    $pre_religiosity_or_faith_rate = $candidateInfo['pre_religiosity_or_faith_rate'];
                    $search->orWhere('pre_religiosity_or_faith_rate', 'like', '%' . $pre_religiosity_or_faith_rate . '%');
                }
                // pre_manners_socialskill_ethics_rate
                if (isset($candidateInfo['pre_manners_socialskill_ethics_rate']) and !empty($candidateInfo['pre_manners_socialskill_ethics_rate'])) {
                    $pre_manners_socialskill_ethics_rate = $candidateInfo['pre_manners_socialskill_ethics_rate'];
                    $search->orWhere('pre_manners_socialskill_ethics_rate', 'like', '%' . $pre_manners_socialskill_ethics_rate . '%');
                }
                // pre_emotional_maturity_rate
                if (isset($candidateInfo['pre_emotional_maturity_rate']) and !empty($candidateInfo['pre_emotional_maturity_rate'])) {
                    $pre_emotional_maturity_rate = $candidateInfo['pre_emotional_maturity_rate'];
                    $search->orWhere('pre_emotional_maturity_rate', 'like', '%' . $pre_emotional_maturity_rate . '%');
                }
                // pre_good_listener_rate
                if (isset($candidateInfo['pre_good_listener_rate']) and !empty($candidateInfo['pre_good_listener_rate'])) {
                    $pre_good_listener_rate = $candidateInfo['pre_good_listener_rate'];
                    $search->orWhere('pre_good_listener_rate', 'like', '%' . $pre_good_listener_rate . '%');
                }

                // pre_good_talker_rate
                if (isset($candidateInfo['pre_good_talker_rate']) and !empty($candidateInfo['pre_good_talker_rate'])) {
                    $pre_good_talker_rate = $candidateInfo['pre_good_talker_rate'];
                    $search->orWhere('pre_good_talker_rate', 'like', '%' . $pre_good_talker_rate . '%');
                }

                // `pre_study_level_id`
                if (isset($candidateInfo['pre_study_level_id']) and !empty($candidateInfo['pre_study_level_id'])) {
                    $per_education_level_id = $candidateInfo['pre_study_level_id'];
                    $search->orWhere('per_education_level_id', 'like', '%' . $per_education_level_id . '%');
                }

                // per_hobbies_interests
                if (isset($per_education_level_id['hobbies_interests']) and !empty($per_education_level_id['hobbies_interests'])) {//
                    $per_hobbies_interests = $per_education_level_id['hobbies_interests'];
                    $search->orWhere('per_hobbies_interests', 'LIKE', '%' . $per_hobbies_interests . '%');
                }
                $gender = $candidateInfo['per_gender'];
                if ($gender == 1) {
                    $gender = 2;
                } elseif ($gender == 2) {
                    $gender = 1;
                } else {
                    $gender = 2;
                }
                $search->where('per_gender', '=', $gender);
                $search->where('user_id', '!=', $userId);
                $page = 1;
                if ($page) {
                    $skip = $parpage * ($page - 1);
                    $queryData = $search->limit($parpage)->offset($skip)->get();
                } else {
                    $queryData = $search->limit($parpage)->offset(0)->get();
                }

                $PaginationCalculation = $search->paginate($parpage);
                $candidate_info = SearchResource::collection($queryData)->filter(function ($value, $key) use ($userId) {
                    return $value->user_id != $userId;
                })->flatten();
                $result['result'] = $candidate_info;
                $result['pagination'] = self::pagination($PaginationCalculation);
                return $this->sendSuccessResponse($result, 'Information fetch Successfully!');
            else:
                $result['result'] = [];
                $result['pagination'] = [];
                return $this->sendSuccessResponse($result, 'user information not found');
            endif;


        elseif ($userInfo['account_type'] == 2):
            $representativeInfo = $this->representativeRepository->findOneBy(['user_id' => $userId]);
            // Religion
//            if (isset($representativeInfo['per_current_residence_country']) and !empty($representativeInfo['per_current_residence_country'])) {
//                $per_current_residence_country = $representativeInfo['per_current_residence_country'];
//                $search->orWhere('per_nationality', 'like', '%' . $per_current_residence_country . '%');
//            }
            $gender = $representativeInfo['per_gender'];
            if ($gender == 1) {
                $gender = 2;
            } elseif ($gender == 2) {
                $gender = 1;
            } else {
                $gender = 2;
            }
            $search->where('per_gender', '=', $gender);
            $page = 1;
            if ($page) {
                $skip = $parpage * ($page - 1);
                $queryData = $search->limit($parpage)->offset($skip)->get();
            } else {
                $queryData = $search->limit($parpage)->offset(0)->get();
            }

            $PaginationCalculation = $search->paginate($parpage);
            $candidate_info = SearchResource::collection($queryData)->where('user_id', '!=', $userId)->where('per_gender', '=', $gender);
            $result['result'] = $candidate_info;
            $result['pagination'] = self::pagination($PaginationCalculation);
            return $this->sendSuccessResponse($result, 'Information fetch Successfully!');

        else:
            $result['result'] = [];
            $result['pagination'] = [];
            return $this->sendSuccessResponse($result, 'Information fetch Successfully!');
        endif;

    }

    /**
     * @param $queryData
     * @return array
     */
    protected function pagination($queryData)
    {
        $data = [
            'total_items' => $queryData->total(),
            'current_items' => $queryData->count(),
            'first_item' => $queryData->firstItem(),
            'last_item' => $queryData->lastItem(),
            'current_page' => $queryData->currentPage(),
            'last_page' => $queryData->lastPage(),
            'has_more_pages' => $queryData->hasMorePages(),
        ];
        return $data;
    }
}