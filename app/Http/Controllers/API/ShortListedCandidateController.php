<?php

namespace App\Http\Controllers\API;

use App\Enums\HttpStatusCode;
use App\Helpers\Notificationhelpers;
use App\Http\Requests\API\CreateShortListedCandidateAPIRequest;
use App\Http\Requests\API\UpdateShortListedCandidateAPIRequest;
use App\Models\BlockList;
use App\Models\CandidateInformation;
use App\Models\Generic;
use App\Models\ShortListedCandidate;
use App\Models\Team;
use App\Models\TeamMember;
use App\Repositories\CandidateRepository;
use App\Repositories\ShortListedCandidateRepository;
use App\Repositories\TeamRepository;
use App\Services\BlockListService;
use App\Traits\CrudTrait;
use App\Transformers\CandidateTransformer;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Http\Controllers\AppBaseController;
use Response;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response as FResponse;
use App\Http\Resources\ShortlistedCandidateResource;

/**
 * Class ShortListedCandidateController
 * @package App\Http\Controllers\API\V1
 */
class ShortListedCandidateController extends AppBaseController
{
    use CrudTrait;

    /**
     * @var BlockListService
     */
    protected $blockListService;

    /** @var  ShortListedCandidateRepository */
    private $shortListedCandidateRepository;

    /**
     * @var CandidateRepository
     */
    protected $candidateRepository;


    /**
     * @var CandidateTransformer
     */
    private $candidateTransformer;
    /**
     * @var TeamRepository
     */
    private $teamRepository;

    public function __construct(
        ShortListedCandidateRepository $shortListedCandidateRepository,
        CandidateRepository $candidateRepository,
        BlockListService $blockListService,
        CandidateTransformer $candidateTransformer,
        TeamRepository $teamRepository
    )
    {
        $this->shortListedCandidateRepository = $shortListedCandidateRepository;
        $this->candidateRepository = $candidateRepository;
        $this->blockListService = $blockListService;
        $this->setActionRepository($shortListedCandidateRepository);
        $this->candidateTransformer = $candidateTransformer;
        $this->teamRepository = $teamRepository;

    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $userId = self::getUserId();

        $perPage = $request->input('parpage',10);

        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }

            $activeTeamId = Generic::getActiveTeamId();

            if (!$activeTeamId) {
                throw new Exception('Team not found, Please create team first');
            }

            $activeTeam = $this->teamRepository->findOneByProperties([
                'id' => $activeTeamId
            ]);

            $userInfo['shortList'] = $activeTeam->teamShortListedUser->pluck('id')->toArray();
            $userInfo['blockList'] = $candidate->blockList->pluck('user_id')->toArray();
            $userInfo['teamList'] = $activeTeam->teamListedUser->pluck('id')->toArray();
            $connectFrom = $candidate->teamConnection->pluck('from_team_id')->toArray();
            $connectTo = $candidate->teamConnection->pluck('to_team_id')->toArray();
            $userInfo['connectList'] = array_unique (array_merge($connectFrom,$connectTo)) ;



            $singleBLockList = $this->blockListService->blockListByUser($userId)->toArray();

            $shortListCandidates = $candidate->shortList()->wherePivot('shortlisted_for',$activeTeam->id)->whereNotIn('candidate_information.user_id',$singleBLockList)->paginate($perPage);

            $candidatesResponse = [];

            foreach ($shortListCandidates as $candidate) {
                $candidate->is_short_listed = in_array($candidate->user_id,$userInfo['shortList']);
                $candidate->is_block_listed = in_array($candidate->user_id,$userInfo['blockList']);
                $candidate->is_teamListed = in_array($candidate->user_id,$userInfo['teamList']);
                $teamId = $candidate->candidateTeam()->exists() ? $candidate->candidateTeam->first()->getTeam->team_id : null;
                $candidate->is_connect = in_array($teamId,$userInfo['connectList']);
                $candidate->team_id = $teamId;
                $candidatesResponse[] = $this->candidateTransformer->transformSearchResult($candidate);
            }

            $pagination = $this->paginationResponse($shortListCandidates);

            return $this->sendSuccessResponse($candidatesResponse, 'Short Listed Candidate Fetch successfully!', $pagination, HttpStatusCode::CREATED);

        }catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function teamShortListedCandidate(Request $request)
    {

        $userId = self::getUserId();

        try {
            $perPage = $request->input('perpage',10);

            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }

            /* Get Active Team instance */
            $activeTeamId = Generic::getActiveTeamId();
            if (!$activeTeamId) {
                throw new Exception('Team not found, Please create team first');
            }
            $activeTeam = $this->teamRepository->findOneByProperties([
                'id' => $activeTeamId
            ]);

            $userInfo['shortList'] = $activeTeam->teamShortListedUser->pluck('id')->toArray();
            $userInfo['blockList'] = $candidate->blockList->pluck('id')->toArray();
            $userInfo['teamList'] = $activeTeam->teamListedUser->pluck('id')->toArray();
            $connectFrom = $candidate->teamConnection->pluck('from_team_id')->toArray();
            $connectTo = $candidate->teamConnection->pluck('to_team_id')->toArray();
            $userInfo['connectList'] = array_unique (array_merge($connectFrom,$connectTo)) ;


            $teamShortListUsers = $activeTeam->teamListedUser()->paginate($perPage) ;
            $teamShortListUsers->load('getCandidate') ;

            $candidatesResponse = [];

            foreach ($teamShortListUsers as $teamShortListUser) {
                $teamShortListUser->getCandidate->is_short_listed = in_array($teamShortListUser->id,$userInfo['shortList']);
                $teamShortListUser->getCandidate->is_block_listed = in_array($teamShortListUser->id,$userInfo['blockList']);
                $teamShortListUser->getCandidate->is_teamListed = in_array($teamShortListUser->id,$userInfo['teamList']);
                $teamId = $teamShortListUser->getCandidate->candidateTeam()->exists() ? $teamShortListUser->getCandidate->candidateTeam->first()->getTeam->team_id : null;
                $teamTableId = $candidate->active_team ? $candidate->active_team->id : '';
                $teamShortListUser->getCandidate->is_connect = $activeTeam->connectedTeam($teamTableId) ? $activeTeam->connectedTeam($teamTableId)->id : null;;
                $teamShortListUser->getCandidate->team_id = $teamId;
                $shortListedBy = CandidateInformation::where('user_id', $teamShortListUser->pivot->team_listed_by)->first();
                $teamShortListUser->pivot->shortlisted_by =$shortListedBy->first_name.' '. $shortListedBy->last_name;
                $candidatesResponse[] = $this->candidateTransformer->transformShortListUser($teamShortListUser);
            }

            $pagination = $this->paginationResponse($teamShortListUsers);

            return $this->sendSuccessResponse($candidatesResponse, 'Short Listed Candidate Fetch successfully!',$pagination, HttpStatusCode::CREATED);

        }catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }


    /**
     * Store a newly created ShortListedCandidate in storage.
     * POST /shortListedCandidates
     *
     * @param CreateShortListedCandidateAPIRequest $request
     *
     * @return Response
     */
    public function store(CreateShortListedCandidateAPIRequest $request)
    {
        $input = $request->all();
        $input['shortlisted_date'] = Carbon::now();
        $input['shortlisted_for'] =  Generic::getActiveTeamId();
        if(!$input['shortlisted_for']){
            return $this->sendErrorResponse('Team Not found, Please make team first');
        }
        $shortListedCandidate = $this->shortListedCandidateRepository->create($input);
//        Notificationhelpers::add('Short Listed Candidate saved successfully', 'single', null, $input['shortlisted_by']);
        return $this->sendResponse($shortListedCandidate->toArray(), 'Short Listed Candidate saved successfully', FResponse::HTTP_CREATED);
    }

    /**
     * Display the specified ShortListedCandidate.
     * GET|HEAD /shortListedCandidates/{id}
     *
     * @param int $id
     *
     * @return Response
     */
    public function show($id)
    {
        /** @var ShortListedCandidate $shortListedCandidate */
        $shortListedCandidate = $this->shortListedCandidateRepository->findOne($id);

        if (empty($shortListedCandidate)) {
            return $this->sendError('Short Listed Candidate not found');
        }

        return $this->sendResponse($shortListedCandidate->toArray(), 'Short Listed Candidate retrieved successfully');
    }

    /**
     * Update the specified ShortListedCandidate in storage.
     * PUT/PATCH /shortListedCandidates/{id}
     *
     * @param int $id
     * @param UpdateShortListedCandidateAPIRequest $request
     *
     * @return Response
     */

    public function update($id, UpdateShortListedCandidateAPIRequest $request)
    {
        $input = $request->all();
        try {

            $shortListedCandidate = $this->shortListedCandidateRepository->findOne($id);
            if (empty($shortListedCandidate)) {
                return $this->sendError('Short Listed Candidate not found');
            }

            $shortListedCandidate->update($input);
            return $this->sendResponse($shortListedCandidate, 'ShortListedCandidate updated successfully');
        } catch (Exception $exception) {
            return $this->sendError($exception->getMessage());
        }
    }

    /**
     * Remove the specified ShortListedCandidate from storage.
     * DELETE /shortListedCandidates/{id}
     *
     * @param int $id
     *
     * @return Response
     * @throws \Exception
     *
     */
    public function destroy($id)
    {
        /** @var ShortListedCandidate $shortListedCandidate */
        $shortListedCandidate = $this->shortListedCandidateRepository->findOne($id);

        if (empty($shortListedCandidate)) {
            return $this->sendError('Short Listed Candidate not found', FResponse::HTTP_NOT_FOUND);
        }

        $shortListedCandidate->delete();
        return $this->sendSuccess([], 'Short Listed Candidate deleted successfully', FResponse::HTTP_OK);
    }

    public function deletedCandidate()
    {
        $deletedCandidate = $this->shortListedCandidateRepository->deletedCandidate();
        $formatted_data = ShortlistedCandidateResource::collection($deletedCandidate);
        return $this->sendResponse($formatted_data, 'Deleted Candidate List');
    }


    public function destroyByCandidate(Request $request)
    {
        $userId = self::getUserId();

        try {
            $candidate = $this->candidateRepository->findOneByProperties([
                'user_id' => $userId
            ]);

            if (!$candidate) {
                throw (new ModelNotFoundException)->setModel(get_class($this->candidateRepository->getModel()), $userId);
            }

            /* Get Active Team instance */
            $activeTeamId = Generic::getActiveTeamId();
            if (!$activeTeamId) {
                throw new Exception('Team not found, Please create team first');
            }

            $candidate->shortList()->detach($request->user_id);

            return $this->sendSuccessResponse([], 'Candidate remove from shortlist successfully!', [], HttpStatusCode::CREATED);

        }catch (Exception $exception) {
            return $this->sendErrorResponse($exception->getMessage());
        }
    }


}
