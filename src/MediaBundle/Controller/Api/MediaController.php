<?php

namespace Opifer\MediaBundle\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    /**
     * Index.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function indexAction(Request $request)
    {
        $this->denyAccessUnlessGranted('ROLE_MEDIA_INDEX');

        $media = $this->get('opifer.media.media_manager')->getPaginatedByRequest($request);

        $response = [
            'results'          => iterator_to_array($media->getCurrentPageResults()),
            'total_results'    => $media->getNbResults(),
            'results_per_page' => $media->getMaxPerPage(),
            'max_upload_size'  => $this->determineMaxUploadSize(),
        ];

        $json = $this->get('jms_serializer')->serialize($response, 'json');

        return JsonResponse::fromJsonString($json);
    }

    private function determineMaxUploadSize() {
        if (ini_get('post_max_size') < ini_get('upload_max_filesize')) {
            return ini_get('post_max_size');
        }

        return ini_get('upload_max_filesize');
    }

    /**
     * Detail.
     *
     * @return JsonResponse
     */
    public function detailAction($id = null)
    {
        $media = $this->get('opifer.media.media_manager')->getRepository()->find($id);

        $json = $this->get('jms_serializer')->serialize($media, 'json');

        return JsonResponse::fromJsonString($json);
    }

    /**
     * Upload.
     *
     * @return Response
     */
    public function uploadAction(Request $request)
    {
        $mediaManager = $this->get('opifer.media.media_manager');

        $em = $this->getDoctrine()->getManager();

        $newMedia = [];
        foreach ($request->files->all() as $files) {
            if ((!is_array($files)) && (!$files instanceof \Traversable)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                $media = $mediaManager->createMedia();
                $media->setFile($file);

                if (strpos($file->getClientMimeType(), 'image') !== false) {
                    $media->setProvider('image');
                } else {
                    $media->setProvider('file');
                }

                $newMedia[] = $media;

                $em->persist($media);
            }
        }
        $em->flush();

        $json = $this->get('jms_serializer')->serialize($newMedia, 'json');

        return JsonResponse::fromJsonString($json);
    }

    /**
     * Delete.
     *
     * @param Request $request
     * @param int     $id
     *
     * @return JsonResponse
     */
    public function deleteAction(Request $request, $id)
    {
        $this->denyAccessUnlessGranted('MEDIA_DELETE');

        try {
            $mediaManager = $this->get('opifer.media.media_manager');

            $media = $mediaManager->getRepository()->find($id);

            $mediaManager->remove($media);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return new JsonResponse(['success' => true]);
    }
}
